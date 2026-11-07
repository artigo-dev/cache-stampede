<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache;

use Artigo\Cache\Stale\Election;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Stampede protection whose scope is the fleet, built on symfony/lock.
 *
 * Symfony's LockRegistry flock()s local files, so its reach ends at the
 * machine: twelve application servers mean twelve requests at the origin. This
 * wrapper takes the same job to a LockFactory, and a LockFactory can be backed
 * by anything the Lock component supports - Redis, Memcached, a database,
 * Zookeeper - so every server races for the same lock.
 *
 *     $pool = new RedisAdapter($redis);
 *     $pool->setCallbackWrapper(new FleetLock(new LockFactory(new RedisStore($redis))));
 *
 * **This is the slower of the two locks in this package, on purpose.** Of the
 * stores that ship with the Lock component, only Flock, Semaphore and the two
 * PostgreSQL ones can block natively; everything else, Redis included, waits
 * by polling on a 100 ms sleep. So a waiter here finds out that the holder has
 * finished about as slowly as it would under LockRegistry - it just no longer
 * duplicates the work while it waits. {@see MemoLock} is woken by a message
 * instead and returns roughly 18 ms behind the holder rather than 100, at the
 * price of being Redis-only.
 *
 * **$beta is read only for INF**, as in {@see MemoLock}: probabilistic early
 * expiration is decided upstream and is not what this class does, while a
 * forced recompute waits for the lock and then takes it rather than going
 * round it.
 *
 * Which is the trade this class exists to make legible: correctness across a
 * fleet costs nothing but a store you already run, and is worth having on its
 * own. The wake-up latency is a separate question, and a separate class.
 */
final class FleetLock implements Election
{
    /**
     * Enough attempts to outlast a holder that dies with the lock, and few
     * enough to give up rather than spin.
     */
    private const ATTEMPTS = 10;

    public function __construct(
        private readonly LockFactory $locks,
        private readonly float $ttl = 30.0,
        private readonly string $prefix = 'cache.stampede.',
    ) {
    }

    public function __invoke(
        callable $callback,
        ItemInterface $item,
        bool &$save,
        CacheInterface $pool,
        ?\Closure $setMetadata = null,
        ?LoggerInterface $logger = null,
        // only INF is read, and only as "recompute now". See the class docblock.
        ?float $beta = null,
    ): mixed {
        if (!$pool instanceof CacheItemPoolInterface) {
            // without a pool there is nothing to read back, so nothing to wait for
            return $callback($item, $save);
        }

        $key = $item->getKey();
        $lock = $this->lock($key);
        $until = Deadline::beforeMaxExecutionTime();

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            try {
                $acquired = $lock->acquire();
            } catch (\Throwable $e) {
                // a lock that cannot be taken must not stop the application
                $logger?->warning('Lock unavailable, computing item "{key}" unprotected: {message}', ['key' => $key, 'message' => $e->getMessage()]);

                return $callback($item, $save);
            }

            if ($acquired) {
                $logger?->info('Lock acquired, now computing item "{key}"', ['key' => $key]);

                try {
                    $value = $callback($item, $save);

                    if ($save) {
                        if (null !== $setMetadata) {
                            $setMetadata($item);
                        }

                        $saved = $pool->save($item->set($value));
                        $save = false;

                        // what save() answered: Symfony 8.2 reports a false
                        // one to the caller in $metadata, earlier versions
                        // take one argument and ignore it
                        if (null !== $setMetadata) {
                            $setMetadata($item, $saved);
                        }
                    }

                    return $value;
                } finally {
                    try {
                        $lock->release();
                    } catch (\Throwable $e) {
                        // thrown from a finally, it would replace the value just
                        // computed; the lock expires on its own
                        $logger?->warning('Could not release the lock on item "{key}": {message}', ['key' => $key, 'message' => $e->getMessage()]);

                        // the lock's destructor tries the release again and
                        // throws; dropped here, it throws where it is caught
                        try {
                            $lock = null;
                        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (the destructor throws, which phpstan does not see)
                        }
                    }
                }
            }

            $logger?->info('Item "{key}" is locked, waiting for the holder', ['key' => $key]);
            $this->waitFor($logger, $key, $until);

            if (null !== $until && microtime(true) >= $until) {
                // max_execution_time is a second away: compute now, unprotected,
                // rather than be killed waiting - the choice LockRegistry makes
                $logger?->warning('max_execution_time is near, computing item "{key}" unprotected', ['key' => $key]);

                return $callback($item, $save);
            }

            if (\INF === $beta) {
                // whoever forced this wants a value computed after they asked
                // for it, not the one the holder already had in flight. Race
                // for the lock again rather than serve what it left - but read
                // it: a pool that guards its writes compares the write with
                // its last read, and the holder has written since ours
                $logger?->info('Force-recomputing item "{key}"', ['key' => $key]);
                $pool->getItem($key);

                continue;
            }

            $waited = $pool->getItem($key);

            if ($waited->isHit()) {
                $logger?->info('Item "{key}" retrieved after the lock was released', ['key' => $key]);
                $save = false;

                return $waited->get();
            }

            // the holder died, or what it wrote expired at once: race for it
            $logger?->info('Item "{key}" still missing after the lock was released, retrying', ['key' => $key]);
        }

        // whoever holds the lock is not letting go; serve the caller rather
        // than keep them waiting on it
        $logger?->warning('Gave up waiting for the lock on item "{key}", computing it anyway', ['key' => $key]);

        return $callback($item, $save);
    }

    /**
     * Blocks until the holder releases, then lets go again: this side wants
     * the value, not the lock.
     *
     * A *read* lock, so that waiters wake together, as LockRegistry does with
     * flock(LOCK_SH). The exclusive lock would hand it to them one at a time:
     * twelve waiters on a 300 ms origin call were served 12 ms behind it on
     * PubSubRedisStore against 7 ms on the read lock, and 106 ms against
     * 108 ms on RedisStore, where the 100 ms poll decides. A store that cannot
     * do shared locks falls back to the exclusive one, which is still correct.
     *
     * The lock is one of its own, so that one whose release failed can be
     * dropped here, where what its destructor throws is caught.
     */
    private function waitFor(?LoggerInterface $logger, string $key, ?float $until = null): void
    {
        $lock = $this->lock($key);
        $shared = $lock instanceof SharedLockInterface;

        try {
            if (null === $until || $until >= microtime(true) + $this->ttl) {
                // blocking, as the component does it: natively on the stores
                // that can, on a 100 ms poll elsewhere, and never for longer
                // than the holder's lock can live
                $acquired = $shared ? $lock->acquireRead(true) : $lock->acquire(true);
            } else {
                // max_execution_time ends before the lock would: poll with that
                // deadline instead (see Deadline), the component's own interval
                while (!$acquired = ($shared ? $lock->acquireRead(false) : $lock->acquire(false))) {
                    if (microtime(true) >= $until) {
                        break;
                    }

                    usleep(100_000);
                }
            }

            if ($acquired) {
                $lock->release();
            }
        } catch (\Throwable $e) {
            // a timeout or a lost store: fall through and read the pool anyway
            $logger?->debug('Stopped waiting on the lock for "{key}": {message}', ['key' => $key, 'message' => $e->getMessage()]);

            // the lock's destructor tries a failed release again and throws;
            // dropped here, it throws where it is caught
            try {
                $lock = null;
            } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (the destructor throws, which phpstan does not see)
            }
        }
    }

    /**
     * Elects the one process that refreshes a stale value (see StaleCache):
     * a lock of its own, taken without waiting.
     */
    public function tryLock(string $key, int $ttlMs): ?\Closure
    {
        // released by the closure alone: a release that threw must not be
        // tried again by the destructor, outside anyone's catch
        $lock = $this->locks->createLock($this->prefix.'refresh.'.$key, $ttlMs / 1000, false);

        if (!$lock->acquire()) {
            return null;
        }

        return static function () use ($lock): void {
            $lock->release();
        };
    }

    /**
     * The lock on an item. Symfony 6.4 promises a LockInterface only, where
     * later versions promise a SharedLockInterface.
     */
    private function lock(string $key): LockInterface
    {
        return $this->locks->createLock($this->prefix.$key, $this->ttl);
    }
}

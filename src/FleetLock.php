<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache;

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
final class FleetLock
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
        $lock = $this->locks->createLock($this->prefix.$key, $this->ttl);

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            try {
                $acquired = $lock->acquire();
            } catch (\Exception $e) {
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

                        $pool->save($item->set($value));
                        $save = false;
                    }

                    return $value;
                } finally {
                    $lock->release();
                }
            }

            $logger?->info('Item "{key}" is locked, waiting for the holder', ['key' => $key]);
            $this->waitFor($lock, $logger, $key);

            if (\INF === $beta) {
                // whoever forced this wants a value computed after they asked
                // for it, not the one the holder already had in flight. Race
                // for the lock again rather than read what it left.
                $logger?->info('Force-recomputing item "{key}"', ['key' => $key]);

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
     * A *read* lock, so that waiters wake together. Taking the exclusive lock
     * would hand it to them one at a time, and the last of twelve would wait
     * out eleven acquire-release cycles before it ever looked at the pool -
     * measured at 486 ms against a 300 ms origin call. LockRegistry avoids the
     * same trap with flock(LOCK_SH); a store that cannot do shared locks falls
     * back to the exclusive one, which is slow but still correct.
     */
    private function waitFor(LockInterface $lock, ?LoggerInterface $logger, string $key): void
    {
        try {
            if ($lock instanceof SharedLockInterface) {
                $lock->acquireRead(true);
            } else {
                $lock->acquire(true);
            }

            $lock->release();
        } catch (\Exception $e) {
            // a timeout or a lost store: fall through and read the pool anyway
            $logger?->debug('Stopped waiting on the lock for "{key}": {message}', ['key' => $key, 'message' => $e->getMessage()]);
        }
    }
}

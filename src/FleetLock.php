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
 * Lets one request compute a missing cache value while the others wait for
 * it, across all servers, with any symfony/lock store.
 *
 * Symfony's LockRegistry locks with flock() on local files, so it only stops
 * requests on the same machine. Example: twelve application servers each get
 * one request for an expired key at the same moment. Each server computes
 * the value once, so the origin is asked twelve times. This wrapper takes its
 * lock from a LockFactory instead. A LockFactory can use any store the Lock
 * component supports - Redis, Memcached, a database, Zookeeper - so every
 * server competes for the same lock, and the origin is asked once.
 *
 *     $pool = new RedisAdapter($redis);
 *     $pool->setCallbackWrapper(new FleetLock(new LockFactory(new RedisStore($redis))));
 *
 * **This is the slower of the two locks in this package, on purpose.** Of the
 * stores that ship with the Lock component, only Flock, Semaphore and the two
 * PostgreSQL ones can wait for a lock natively. All the others, Redis
 * included, wait by trying again every 100 ms. So a waiter here learns that
 * the holder is done about as late as under LockRegistry; the difference is
 * that the work is no longer done twice. On RedisStore the waiters are served
 * about 108 ms after the holder. {@see MemoLock} wakes its waiters with a
 * message and serves them about 4 ms after the holder, but it needs Redis.
 *
 * **$beta is read only for INF**, as in {@see MemoLock}. Probabilistic early
 * expiration is decided by Symfony before this wrapper is called, and is not
 * what this class does. A forced recompute (INF) waits for the lock and then
 * takes it, instead of skipping the lock.
 *
 * That is the trade this class makes plain: computing a value once across the
 * fleet needs nothing but a store you already run, and is worth having on its
 * own. How fast the waiters wake up is a separate question, answered by
 * separate classes (MemoLock, and FleetLock on PubSubRedisStore).
 */
final class FleetLock implements Election
{
    /**
     * How many times a waiter waits for the lock and tries again before it
     * computes the value anyway. That is enough to outlast a holder that died
     * while holding the lock, and few enough that a lock nobody releases does
     * not keep the request waiting forever.
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
            // a pool without getItem() cannot be read after the wait, so a
            // waiter could never see what the holder stored: just compute
            return $callback($item, $save);
        }

        $key = $item->getKey();
        $lock = $this->lock($key);
        $until = Deadline::beforeMaxExecutionTime();

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            try {
                $acquired = $lock->acquire();
            } catch (\Throwable $e) {
                // the store cannot be reached: compute without the lock,
                // because a lock must never be the reason a request fails
                $logger?->warning('Lock unavailable, computing item "{key}" unprotected: {message}', ['key' => $key, 'message' => $e->getMessage()]);

                return $callback($item, $save);
            }

            if ($acquired) {
                $logger?->info('Lock acquired, now computing item "{key}"', ['key' => $key]);

                try {
                    $value = $callback($item, $save);

                    // save the value here, before the lock is released below,
                    // so that the waiters find it when they wake up. $save is
                    // then false, so Symfony does not save it a second time
                    if ($save) {
                        // let Symfony note the expiry and the time the
                        // computation took on the item, before it is saved
                        if (null !== $setMetadata) {
                            $setMetadata($item);
                        }

                        $saved = $pool->save($item->set($value));
                        $save = false;

                        // pass on whether the save worked. Symfony 8.2 reports
                        // a failed save to the caller in $metadata. Earlier
                        // versions take one argument and ignore the second
                        if (null !== $setMetadata) {
                            $setMetadata($item, $saved);
                        }
                    }

                    return $value;
                } finally {
                    try {
                        $lock->release();
                    } catch (\Throwable $e) {
                        // an exception thrown from a finally block would
                        // replace the value just computed, so only log it. The
                        // lock expires on its own
                        $logger?->warning('Could not release the lock on item "{key}": {message}', ['key' => $key, 'message' => $e->getMessage()]);

                        // the Lock object's destructor tries the release again,
                        // and throws when that fails too. Destroy it here,
                        // inside a try, so that exception is caught
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
                // max_execution_time is a second away: compute now without the
                // lock, rather than be killed by PHP while still waiting.
                // LockRegistry does the same
                $logger?->warning('max_execution_time is near, computing item "{key}" unprotected', ['key' => $key]);

                return $callback($item, $save);
            }

            if (\INF === $beta) {
                // a forced recompute wants a value computed after it asked, not
                // the one the holder was already computing. So do not serve
                // what the holder stored: go round and take the lock. But read
                // the item first. A pool that guards its writes compares a
                // write with the last read of the key, and the holder has
                // written since this request read it
                $logger?->info('Force-recomputing item "{key}"', ['key' => $key]);
                $pool->getItem($key);

                continue;
            }

            // the wait is over: serve what the holder stored, if it is there
            $waited = $pool->getItem($key);

            if ($waited->isHit()) {
                $logger?->info('Item "{key}" retrieved after the lock was released', ['key' => $key]);
                $save = false;

                return $waited->get();
            }

            // a miss: the holder died, what it stored has already expired, or
            // the wait ended before the holder was done. Go round again: take
            // the lock, or wait once more
            $logger?->info('Item "{key}" still missing after the lock was released, retrying', ['key' => $key]);
        }

        // after ATTEMPTS waits the lock is still held: compute the value
        // without it, rather than keep the caller waiting any longer
        $logger?->warning('Gave up waiting for the lock on item "{key}", computing it anyway', ['key' => $key]);

        return $callback($item, $save);
    }

    /**
     * Waits until the holder releases the lock, then releases it again at
     * once. The waiter wants the value the holder stores, not the lock.
     *
     * It waits with a *read* lock, so that all waiters wake up together, as
     * LockRegistry does with flock(LOCK_SH). With the exclusive lock they
     * would get it one after the other. Measured with twelve waiters on a
     * 300 ms origin call: on PubSubRedisStore they were served 7 ms after it
     * with the read lock and 12 ms with the exclusive lock; on RedisStore
     * 108 ms and 106 ms, because there the 100 ms retry decides. A store that
     * cannot do shared locks uses the exclusive lock, which is still correct.
     *
     * It creates a Lock object of its own, separate from the caller's. So if
     * releasing it fails, the object can be destroyed here, where the
     * exception its destructor throws is caught.
     */
    private function waitFor(?LoggerInterface $logger, string $key, ?float $until = null): void
    {
        $lock = $this->lock($key);
        $shared = $lock instanceof SharedLockInterface;

        try {
            if (null === $until || $until >= microtime(true) + $this->ttl) {
                // a blocking acquire, the component's own way to wait:
                // natively on the stores that can, by trying again every
                // 100 ms on the others. The holder's lock expires within $ttl,
                // and $ttl ends before max_execution_time, so the wait does too
                $acquired = $shared ? $lock->acquireRead(true) : $lock->acquire(true);
            } else {
                // max_execution_time ends before the holder's lock would
                // expire: try without blocking every 100 ms, the component's
                // own interval, and stop at that deadline (see Deadline)
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
            // the wait timed out, or the store went away: stop waiting, and
            // let the caller read the pool anyway
            $logger?->debug('Stopped waiting on the lock for "{key}": {message}', ['key' => $key, 'message' => $e->getMessage()]);

            // the Lock object's destructor tries a failed release again, and
            // throws when that fails too. Destroy it here, inside a try, so
            // that exception is caught
            try {
                $lock = null;
            } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (the destructor throws, which phpstan does not see)
            }
        }
    }

    /**
     * Picks the one process that refreshes a stale value (see StaleCache).
     * It is a lock of its own, separate from the item lock, taken without
     * waiting: whoever gets it refreshes, the others serve the stale value.
     */
    public function tryLock(string $key, int $ttlMs): ?\Closure
    {
        // autoRelease is off, so only the returned closure releases it.
        // Otherwise, after a release that threw, the destructor would try
        // again and throw somewhere no code catches it
        $lock = $this->locks->createLock($this->prefix.'refresh.'.$key, $ttlMs / 1000, false);

        if (!$lock->acquire()) {
            return null;
        }

        return static function () use ($lock): void {
            $lock->release();
        };
    }

    /**
     * The lock for one cache item. The return type is LockInterface because
     * Symfony 6.4's createLock() promises only that; later versions promise a
     * SharedLockInterface.
     */
    private function lock(string $key): LockInterface
    {
        return $this->locks->createLock($this->prefix.$key, $this->ttl);
    }
}

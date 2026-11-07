<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Store;

use Artigo\Cache\Deadline;
use Artigo\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\BlockingSharedLockStoreInterface;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Store\RedisStore;

/**
 * Symfony's RedisStore, but a waiter is woken by a message instead of
 * checking the lock every 100 ms.
 *
 * The Lock component has no way for a store to announce that a lock was
 * released. So a blocking acquire on a RedisStore tries save() again every
 * 100 ms, and a waiter learns that the holder is done up to 100 ms late.
 * Example: twelve requests compute a value that takes 300 ms, through
 * FleetLock. On RedisStore they take about 108 ms longer than the
 * computation; on this store about 7 ms longer.
 *
 * This store adds the two blocking interfaces the component offers,
 * waitAndSave() and waitAndSaveRead(). A waiter listens on a Pub/Sub channel
 * named after the key. When the holder releases the lock, delete() sends a
 * message on that channel, and the waiter tries again. A waiter never waits
 * longer than the holder's lock has left to live (its PTTL), so a holder
 * that died is not waited for after its lock has expired. It also never
 * waits past max_execution_time.
 *
 * Everything else - the tokens, the read locks, the refresh, the Lua scripts
 * that guard them - is RedisStore's own. So FleetLock on this store gets
 * MemoLock's fast wake-up with no Redis code of its own. This class is a
 * working prototype of how the wake-up could look inside symfony/lock.
 *
 * Like MemoLock, it needs a second connection for SUBSCRIBE, because a
 * connection in subscribe mode cannot send any other command. fromDsn()
 * opens both.
 */
final class PubSubRedisStore extends RedisStore implements BlockingStoreInterface, BlockingSharedLockStoreInterface
{
    private const CHANNEL_PREFIX = 'lock:wake:';
    private const WAKE_MESSAGE = '1';

    /**
     * How long one SUBSCRIBE listens before the waiter tries the lock again.
     * If the wake-up message went out before the waiter subscribed, the
     * waiter loses at most this long, not the whole timeout.
     */
    private const WAKE_SLICE_MS = 100;

    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster|null $subscriber = null;

    /**
     * @param \Closure():(\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster) $subscriberFactory opens the second connection, used for SUBSCRIBE
     * @param float                                                         $initialTtl        how long a lock lives, in seconds, passed on to RedisStore
     * @param int                                                           $waitTimeoutMs     how long a blocking acquire waits before it gives up with LockConflictedException
     */
    public function __construct(
        private readonly \Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis,
        private readonly \Closure $subscriberFactory,
        float $initialTtl = 300.0,
        private readonly int $waitTimeoutMs = 30_000,
    ) {
        if ($waitTimeoutMs < 1) {
            throw new InvalidArgumentException(\sprintf('Argument "$waitTimeoutMs" must be a positive number of milliseconds, %d given.', $waitTimeoutMs));
        }

        parent::__construct($redis, $initialTtl);
    }

    /**
     * Opens both connections from one DSN, through Symfony's own connection
     * factory. So every DSN that Symfony understands works here too.
     */
    public static function fromDsn(string $dsn, float $initialTtl = 300.0, int $waitTimeoutMs = 30_000): self
    {
        $redis = RedisAdapter::createConnection($dsn, ['lazy' => true]);

        if (!$redis instanceof \Redis
            && !$redis instanceof \RedisArray
            && !$redis instanceof \RedisCluster
            && !$redis instanceof \Relay\Relay
            && !$redis instanceof \Relay\Cluster
        ) {
            throw new InvalidArgumentException(\sprintf('%s needs an ext-redis or Relay connection, "%s" given. Pass one to the constructor instead.', self::class, get_debug_type($redis)));
        }

        return new self(
            $redis,
            static function () use ($dsn): \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster {
                $subscriber = RedisAdapter::createConnection($dsn, ['lazy' => false]);

                if (!$subscriber instanceof \Redis
                    && !$subscriber instanceof \RedisCluster
                    && !$subscriber instanceof \Relay\Relay
                    && !$subscriber instanceof \Relay\Cluster
                ) {
                    throw new InvalidArgumentException(\sprintf('%s can only SUBSCRIBE on an ext-redis or Relay connection, "%s" given.', self::class, get_debug_type($subscriber)));
                }

                return $subscriber;
            },
            $initialTtl,
            $waitTimeoutMs,
        );
    }

    public function waitAndSave(Key $key): void
    {
        $this->waitFor($key, fn () => $this->save($key));
    }

    public function waitAndSaveRead(Key $key): void
    {
        $this->waitFor($key, fn () => $this->saveRead($key));
    }

    /**
     * Releases the lock as RedisStore does, then wakes the waiters. The
     * message goes out even when the delete failed. A waiter does not trust
     * the message, it simply tries the lock again. So an extra message costs
     * one round trip, and a missing one costs a waiter one slice.
     */
    public function delete(Key $key): void
    {
        try {
            parent::delete($key);
        } finally {
            try {
                $this->redis->publish(self::CHANNEL_PREFIX.$key, self::WAKE_MESSAGE);
            } catch (\Exception) {
                // the lock expires on its own, and waiters notice within one
                // slice
            }
        }
    }

    /**
     * Tries to take the lock. While somebody else holds it, waits for their
     * message and tries again. Each wait lasts one slice at most, and never
     * longer than the holder's lock has left to live. The whole loop stops at
     * $waitTimeoutMs, or a second before max_execution_time if that comes
     * first.
     *
     * @param \Closure():void $attempt save() or saveRead(); throws LockConflictedException while the lock is held
     *
     * @throws LockConflictedException when the lock is still held at the deadline
     */
    private function waitFor(Key $key, \Closure $attempt): void
    {
        $deadline = microtime(true) + $this->waitTimeoutMs / 1000;

        // never past max_execution_time (see Deadline)
        if (null !== $until = Deadline::beforeMaxExecutionTime()) {
            $deadline = min($deadline, $until);
        }

        $lockKey = (string) $key;
        $channel = self::CHANNEL_PREFIX.$lockKey;

        while (true) {
            try {
                $attempt();

                return;
            } catch (LockConflictedException) {
                // held by somebody else: wait for their message
            }

            if (microtime(true) >= $deadline) {
                throw new LockConflictedException(\sprintf('The "%s" lock is still held.', $lockKey));
            }

            $remaining = $this->remaining($lockKey);

            if (null === $remaining) {
                // the lock is gone, or Redis cannot be asked: try to take it
                // again right away
                continue;
            }

            // one slice, or less when the deadline is closer
            $slice = min(($deadline - microtime(true)) * 1000, self::WAKE_SLICE_MS);

            if ($remaining > 0) {
                // a holder that crashed never sends the message, so do not
                // listen much longer than its lock has left to live
                $slice = min($slice, $remaining + 25);
            }

            if ($slice > 0) {
                $this->listen($channel, $slice);
            }
        }
    }

    /**
     * How long the lock has left to live, in milliseconds. 0 when it has no
     * expiry. Null when it is gone, or when Redis cannot be asked.
     */
    private function remaining(string $lockKey): ?int
    {
        try {
            $ttl = $this->redis->pttl($lockKey);
        } catch (\Exception) {
            return null;
        }

        if (!\is_int($ttl) || -2 === $ttl) {
            return null;
        }

        return max(0, $ttl);
    }

    /**
     * Listens on the wake channel for up to $sliceMs. Returns true when a
     * message arrived, and false when the slice passed without one or the
     * connection failed. It handles ext-redis and Relay the same way
     * MemoLock does.
     */
    private function listen(string $channel, float $sliceMs): bool
    {
        try {
            $subscriber = $this->subscriber ??= ($this->subscriberFactory)();
        } catch (\Exception) {
            return false;
        }

        try {
            $subscriber->setOption(
                $subscriber instanceof \Relay\Relay || $subscriber instanceof \Relay\Cluster
                    ? \Relay\Relay::OPT_READ_TIMEOUT
                    : \Redis::OPT_READ_TIMEOUT,
                max(0.001, $sliceMs / 1000),
            );
            // the first message ends the wait. ext-redis ignores an exception
            // thrown from this callback and keeps listening, so the callback
            // unsubscribes, which is the only way to stop it
            $subscriber->subscribe([$channel], static function (\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $connection, string $subscribed): void {
                $connection->unsubscribe([$subscribed]);
            });

            return true;
        } catch (\Exception) {
            // the read timed out because no message came within the slice, or
            // Redis went away
        }

        // When the read times out, ext-redis leaves the connection
        // subscribed, so we unsubscribe before the next wait uses it. Relay
        // has already ended the subscription itself, and answers the
        // unsubscribe with a "Not subscribed" warning, not an exception. The
        // error handler set here silences that warning.
        set_error_handler(static fn (): bool => true);

        try {
            $subscriber->unsubscribe([$channel]);
        } catch (\Exception) {
            // the connection cannot be used any more: close it, and the next
            // wait opens a new one
            $this->subscriber = null;

            try {
                $subscriber->close();
            } catch (\Exception) {
            }
        } finally {
            restore_error_handler();
        }

        return false;
    }
}

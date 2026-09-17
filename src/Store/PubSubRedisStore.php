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
 * Symfony's RedisStore, with waiters woken by a message.
 *
 * The Lock component has no way for a store to announce a release, so a
 * blocking acquire on a RedisStore retries save() on a 100 ms sleep: a waiter
 * finds out that the holder has finished up to 100 ms late. This store adds
 * the two blocking interfaces the component offers - waitAndSave() and
 * waitAndSaveRead() - and honours them by blocking on a Pub/Sub channel named
 * after the key until the holder's delete() publishes on it, then trying
 * again. The wait is bounded by the holder's PTTL, so a holder that died is
 * not waited for beyond its lock, and never runs past max_execution_time.
 *
 * Everything else - the tokens, the read locks, the refresh, the Lua that
 * guards them - is RedisStore's own. FleetLock on this store gets MemoLock's
 * wake-up with no Redis code of its own, which is the shape this would take
 * in symfony/lock: the RFC's option 3, as a prototype.
 *
 * Needs a second connection for the blocking SUBSCRIBE, as MemoLock does: a
 * connection in subscribe mode serves nothing else. fromDsn() opens both.
 */
final class PubSubRedisStore extends RedisStore implements BlockingStoreInterface, BlockingSharedLockStoreInterface
{
    private const CHANNEL_PREFIX = 'lock:wake:';
    private const WAKE_MESSAGE = '1';

    /**
     * How long one SUBSCRIBE blocks before the lock is looked at again, so a
     * wake-up published before the subscription began costs a slice, not the
     * whole timeout.
     */
    private const WAKE_SLICE_MS = 100;

    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster|null $subscriber = null;

    /**
     * @param \Closure():(\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster) $subscriberFactory opens the second connection, for the blocking SUBSCRIBE
     * @param float                                                         $initialTtl        the expiration delay of locks, in seconds, as RedisStore takes it
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
     * Builds both connections from one DSN, through Symfony's own connection
     * factory - so every DSN it understands works here unchanged.
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
     * Releases as RedisStore does, then wakes the waiters. The wake-up goes
     * out whether the delete went through or not: a waiter re-tries rather
     * than trusts it, so one too many costs a round trip and one too few a
     * slice.
     */
    public function delete(Key $key): void
    {
        try {
            parent::delete($key);
        } finally {
            try {
                $this->redis->publish(self::CHANNEL_PREFIX.$key, self::WAKE_MESSAGE);
            } catch (\Exception) {
                // the lock expires on its own; waiters notice within a slice
            }
        }
    }

    /**
     * Tries, waits for the holder's word, tries again - a slice at a time,
     * never longer than the holder's lock can live, never past the timeout
     * or max_execution_time.
     *
     * @param \Closure():void $attempt save() or saveRead(); throws LockConflictedException while the lock is held
     *
     * @throws LockConflictedException when the lock is still held at the deadline
     */
    private function waitFor(Key $key, \Closure $attempt): void
    {
        $deadline = microtime(true) + $this->waitTimeoutMs / 1000;

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
                // held by somebody else: wait for their word
            }

            if (microtime(true) >= $deadline) {
                throw new LockConflictedException(\sprintf('The "%s" lock is still held.', $lockKey));
            }

            $remaining = $this->remaining($lockKey);

            if (null === $remaining) {
                // gone, or unreachable: race for it again right away
                continue;
            }

            $slice = min(($deadline - microtime(true)) * 1000, self::WAKE_SLICE_MS);

            if ($remaining > 0) {
                // a crashed holder never publishes: never wait much longer than
                // its lock can live
                $slice = min($slice, $remaining + 25);
            }

            if ($slice > 0) {
                $this->listen($channel, $slice);
            }
        }
    }

    /**
     * How long the lock has left, in milliseconds; 0 when it has no expiry;
     * null when it is gone or cannot be asked.
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
     * Blocks on the wake channel for up to $sliceMs. True when a message
     * arrived, false when the slice passed without one or the connection
     * failed - the same dance MemoLock does, for the same two clients.
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
            // ext-redis discards an exception thrown from here and carries on
            // blocking, so unsubscribing is the only way to end the wait
            $subscriber->subscribe([$channel], static function (\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $connection, string $subscribed): void {
                $connection->unsubscribe([$subscribed]);
            });

            return true;
        } catch (\Exception) {
            // the slice passed without a message, or Redis went away
        }

        // ext-redis leaves the connection in subscribe mode when the read
        // times out, so it has to be taken out of it before another wait can
        // use it. Relay ends the subscription itself and answers "Not
        // subscribed", as a warning rather than an exception, which is what
        // the handler is here to swallow.
        set_error_handler(static fn (): bool => true);

        try {
            $subscriber->unsubscribe([$channel]);
        } catch (\Exception) {
            // it is beyond saving: the next wait opens a fresh one
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

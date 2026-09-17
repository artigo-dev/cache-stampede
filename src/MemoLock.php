<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache;

use Artigo\Cache\Exception\InvalidArgumentException;
use Artigo\Cache\Exception\LockUnavailable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * A distributed replacement for Symfony's LockRegistry.
 *
 * Symfony protects against cache stampedes by flock()ing a fixed set of local
 * files. That scope is one machine: on a fleet of N application servers a cold
 * key reaches the origin N times, which on a large enough fleet is what no
 * protection looks like. This wrapper moves the lock into Redis, so the whole
 * fleet races for the same key and exactly one request computes, whatever the
 * fleet size. Waiters block on a Pub/Sub channel instead of polling flock() on
 * a sleep cycle, so they wake as soon as the holder is done.
 *
 * It plugs into the public extension point every Symfony adapter exposes:
 *
 *     $pool = new RedisAdapter($redis);
 *     $pool->setCallbackWrapper(MemoLock::fromDsn('redis://127.0.0.1'));
 *
 * No subclassing, no fork, and it works with any adapter - filesystem,
 * chained, tag-aware - not only Redis ones. Setting the wrapper explicitly
 * also overrides Symfony's default of disabling locking under the CLI SAPI,
 * which is what lets the benchmarks measure it.
 *
 * The lock is four plain commands and no script: `SET NX PX` takes it,
 * `SET ... IFEQ` extends it while it is still ours, `DELEX ... IFEQ` releases
 * it on the same condition, and `PUBLISH` wakes whoever is waiting. The two
 * conditional forms are Redis 8.4; Valkey spells the release `DELIFEQ`, and a
 * server with neither - Redis before 8.4, Valkey before 9 - gets a
 * compare-and-set script for each instead, learnt from its first refusal (see
 * RedisCommand).
 *
 * A waiter never waits past max_execution_time: a second before it, the item
 * is computed unprotected instead, as LockRegistry does (see Deadline).
 *
 * **Probabilistic early expiration is not what this class does**, and $beta is
 * not read. Recomputing a little before expiry, at random, hoping the herd
 * never forms, is the other answer to this problem and the answer this class
 * exists because of; it is also decided upstream in Symfony's ContractsTrait,
 * which is what calls this wrapper in the first place, so there is nothing
 * here to opt out of.
 *
 * INF is not that guess. It arrives on the same parameter but means something
 * explicit - recompute this now - and it is honoured the way `LockRegistry`
 * honours it: the caller waits for the lock like any other waiter and then
 * takes it and computes, rather than reading what the holder left. Going round
 * the lock instead would let a forced refresh stampede, which is the one thing
 * this class is here to prevent.
 *
 * A waiter looks at the lock before every SUBSCRIBE and blocks for at most
 * WAKE_SLICE_MS at a time, so a wake-up published in the window before the
 * subscription began - the holder was quick - costs at most one slice, not
 * the whole $waitTimeoutMs. The subscriber connection is opened once per
 * instance and kept.
 */
final class MemoLock
{
    private const LOCK_PREFIX = 'memolock:lock:';
    private const WAKE_PREFIX = 'memolock:wake:';

    /**
     * Released on the wake channel, and checked nowhere: a waiter re-reads the
     * pool rather than trusting a message it cannot authenticate.
     */
    private const WAKE_MESSAGE = '1';

    /**
     * How many times once() and exclusively() will wait and look again before
     * giving up. Enough to outlast a holder that died with the lock, few
     * enough to give up rather than spin.
     */
    private const ATTEMPTS = 10;

    /**
     * How long one SUBSCRIBE blocks before the lock is looked at again. A
     * wake-up that was published before the subscription began is noticed
     * within this - which is LockRegistry's own poll granularity - rather
     * than at the end of the wait.
     */
    private const WAKE_SLICE_MS = 100;

    /**
     * The one connection this instance keeps in subscribe mode for its waits.
     */
    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster|null $subscriber = null;

    /**
     * @param \Closure():(\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster)|null $subscriberFactory opens a
     *                                                                                              *second* connection for the blocking SUBSCRIBE; a connection in subscribe mode cannot serve
     *                                                                                              anything else, so it must not be the one used for locking. Without it, waiters poll the lock
     *                                                                                              key instead - still one origin call, just a slower wake-up.
     */
    public function __construct(
        private readonly \Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis,
        private readonly ?\Closure $subscriberFactory = null,
        private readonly int $lockTtlMs = 30_000,
        private readonly int $waitTimeoutMs = 5_000,
        private readonly int $pollIntervalMs = 25,
    ) {
        foreach (['lockTtlMs' => $lockTtlMs, 'waitTimeoutMs' => $waitTimeoutMs, 'pollIntervalMs' => $pollIntervalMs] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException(\sprintf('Argument "$%s" must be a positive number of milliseconds, %d given.', $name, $value));
            }
        }
    }

    /**
     * Builds both connections from one DSN, through Symfony's own connection
     * factory - so every DSN it understands (cluster, sentinel, Relay, TLS,
     * auth) works here unchanged.
     */
    public static function fromDsn(string $dsn, int $lockTtlMs = 30_000, int $waitTimeoutMs = 5_000): self
    {
        // lazily: an unreachable Redis must surface as a degraded cache on the
        // first command, where we can fall back to computing, and never as a
        // failure to wire the application up
        $redis = RedisAdapter::createConnection($dsn, ['lazy' => true]);

        if (!$redis instanceof \Redis
            && !$redis instanceof \RedisArray
            && !$redis instanceof \RedisCluster
            && !$redis instanceof \Relay\Relay
            && !$redis instanceof \Relay\Cluster
        ) {
            throw new InvalidArgumentException(\sprintf('MemoLock needs an ext-redis or Relay connection, "%s" given. Pass one to the constructor instead.', get_debug_type($redis)));
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
                    throw new InvalidArgumentException(\sprintf('MemoLock can only SUBSCRIBE on an ext-redis or Relay connection, "%s" given.', get_debug_type($subscriber)));
                }

                return $subscriber;
            },
            $lockTtlMs,
            $waitTimeoutMs,
        );
    }

    /**
     * Runs something with nobody else running it at the same time.
     *
     *     $lock->exclusively('import:companies', function (): void {
     *         $this->importCompanies();
     *     });
     *
     * A waiter here wants the lock, not a result, so it is woken and tries
     * again rather than looking for something to collect. That wake-up is the
     * reason this exists beside `symfony/lock`: on eight workers holding a
     * 200 ms section in turn, the handovers cost 9 ms in total against 119 ms
     * when the waiters poll.
     *
     * **What `symfony/lock` still does better:** it releases on destruct, so
     * a process that exits or fatals mid-section hands the lock back at
     * shutdown, where a finally does not run at all. What that case costs
     * here is the lock's remaining TTL, which is also the longest a waiter
     * will wait for it. Work that may outrun `$lockTtlMs` is handed a
     * {@see KeepAlive}.
     *
     * Unlike everything else in this class, this **throws** rather than
     * degrading: {@see LockUnavailable} when the lock cannot be reached or is
     * still held after every attempt. Running anyway would be the one answer a
     * caller asking for exclusivity cannot use.
     *
     * @template T
     *
     * @param \Closure(KeepAlive): T $run
     *
     * @return T
     *
     * @throws LockUnavailable when the lock cannot be reached or never frees
     * @throws \Throwable      whatever $run throws, once the lock is handed back
     */
    public function exclusively(string $key, \Closure $run, ?LoggerInterface $logger = null): mixed
    {
        [$lockKey, $channel] = $this->keysFor($key);
        $token = bin2hex(random_bytes(16));
        $until = Deadline::beforeMaxExecutionTime();

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            try {
                $acquired = $this->acquire($lockKey, $token);
            } catch (\Exception $e) {
                throw new LockUnavailable(\sprintf('Could not reach the lock for "%s": %s', $key, $e->getMessage()), 0, $e);
            }

            if ($acquired) {
                $logger?->info('Lock acquired, running "{key}" exclusively', ['key' => $key]);

                try {
                    return $run($this->keepAlive($key, $lockKey, $token));
                } finally {
                    $this->release($lockKey, $channel, $token, $logger);
                }
            }

            $logger?->info('"{key}" is held elsewhere, waiting for it', ['key' => $key]);
            $this->wait($lockKey, $channel, $logger, $until);

            if (null !== $until && microtime(true) >= $until) {
                // exclusivity was asked for, and there is no time left to wait
                // for it: say so, rather than run beside the holder
                throw new LockUnavailable(\sprintf('"%s" is still held, and max_execution_time is a second away.', $key));
            }
        }

        throw new LockUnavailable(\sprintf('"%s" was still held after %d attempts.', $key, self::ATTEMPTS));
    }

    /**
     * Produce something once, while everyone else waits for the thing rather
     * than for the lock.
     *
     * The pattern php-library calls a standalone MemoLock: a thumbnail, a
     * generated file, a provisioned resource. One caller makes it; the rest
     * block until it exists and then carry on with it, instead of queueing to
     * make it again.
     *
     *     $thumbnail = $lock->once(
     *         'thumbnails:'.$file,
     *         exists: static fn () => is_file($out) ? $out : null,
     *         make: function () use ($file, $out) {
     *             $this->render($file, $out);
     *
     *             return $out;
     *         },
     *     );
     *
     * **This is not a mutex, and symfony/lock is the better one** - it
     * refreshes a held lock, releases on destruct, and speaks to a dozen
     * stores. Use it for "only one worker may run this at a time". Use this
     * when the waiters want the *result*: its one advantage is that they are
     * woken by a message rather than by a poll, which was 16 ms against 107 ms
     * where the stampede benchmark could measure it.
     *
     * $exists is asked before the lock is taken, so a caller arriving after the
     * work is done never touches Redis for a lock at all. Returning null means
     * "not there yet".
     *
     * @param \Closure():mixed           $exists the thing, or null if it is not there yet
     * @param \Closure(KeepAlive): mixed $make   produce it, and return it
     */
    public function once(
        string $key,
        \Closure $exists,
        \Closure $make,
        ?LoggerInterface $logger = null,
    ): mixed {
        $found = self::look($exists);

        if (null !== $found) {
            return $found;
        }

        [$lockKey, $channel] = $this->keysFor($key);
        $token = bin2hex(random_bytes(16));
        $until = Deadline::beforeMaxExecutionTime();

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            try {
                $acquired = $this->acquire($lockKey, $token);
            } catch (\Exception $e) {
                // a lock that cannot be taken must not stop the work
                $logger?->warning('MemoLock unreachable, producing "{key}" unprotected: {message}', ['key' => $key, 'message' => $e->getMessage()]);

                return $make(KeepAlive::unheld($key));
            }

            if ($acquired) {
                $logger?->info('Lock acquired, now producing "{key}"', ['key' => $key]);

                try {
                    return $make($this->keepAlive($key, $lockKey, $token));
                } finally {
                    $this->release($lockKey, $channel, $token, $logger);
                }
            }

            $logger?->info('"{key}" is being produced, waiting for the holder', ['key' => $key]);
            $this->wait($lockKey, $channel, $logger, $until);

            $produced = self::look($exists);

            if (null === $produced && null !== $until && microtime(true) >= $until) {
                // max_execution_time is a second away: produce now, unprotected,
                // rather than be killed waiting
                $logger?->warning('max_execution_time is near, producing "{key}" unprotected', ['key' => $key]);

                return $make(KeepAlive::unheld($key));
            }

            if (null !== $produced) {
                $logger?->info('"{key}" was there after the lock was released', ['key' => $key]);

                return $produced;
            }

            // the holder died, or produced nothing: race for it
            $logger?->info('"{key}" still missing after the lock was released, retrying', ['key' => $key]);
        }

        $logger?->warning('Gave up waiting for "{key}", producing it anyway', ['key' => $key]);

        return $make(KeepAlive::unheld($key));
    }

    /**
     * The same lock with different timings, for work that does not look like
     * the rest.
     *
     * A slow resolver wants a lock that outlasts it - an image render, an
     * external API - because a lock that expires underneath the holder lets
     * everybody else start the same work. Pair it with {@see around()} to
     * apply it to a single call.
     *
     *     $slow = $lock->with(lockTtlMs: 30_000, waitTimeoutMs: 30_000);
     */
    public function with(
        ?int $lockTtlMs = null,
        ?int $waitTimeoutMs = null,
        ?int $pollIntervalMs = null,
    ): self {
        return new self(
            $this->redis,
            $this->subscriberFactory,
            $lockTtlMs ?? $this->lockTtlMs,
            $waitTimeoutMs ?? $this->waitTimeoutMs,
            $pollIntervalMs ?? $this->pollIntervalMs,
        );
    }

    /**
     * Runs one call under this lock, and puts back whatever was there before.
     *
     *     $value = $lock->with(lockTtlMs: 30_000)->around(
     *         $pool,
     *         static fn () => $pool->get('external-api', $resolver),
     *     );
     *
     * Symfony's `get()` takes no room for a per-call setting - its signature is
     * `($key, $callback, $beta, &$metadata)` and $beta governs early expiry,
     * not locking - but `setCallbackWrapper()` hands back what it replaced, so
     * a call can borrow a different lock and give it back. The restoring is in
     * a finally, which is the part that gets forgotten when this is written by
     * hand.
     *
     * @template T
     *
     * @param \Closure():T $call
     *
     * @return T
     */
    public function around(CacheInterface $pool, \Closure $call): mixed
    {
        return self::wrapping($pool, $this, $call);
    }

    /**
     * Runs one call with no lock at all, for a key cheap enough not to be
     * worth protecting.
     *
     *     $value = MemoLock::unguarded($pool, static fn () => $pool->get('cheap', $resolver));
     *
     * @template T
     *
     * @param \Closure():T $call
     *
     * @return T
     */
    public static function unguarded(CacheInterface $pool, \Closure $call): mixed
    {
        return self::wrapping($pool, null, $call);
    }

    /**
     * @template T
     *
     * @param \Closure():T $call
     *
     * @return T
     */
    private static function wrapping(CacheInterface $pool, ?self $lock, \Closure $call): mixed
    {
        if (!method_exists($pool, 'setCallbackWrapper')) {
            // a pool that has no wrapper to swap simply runs the call
            return $call();
        }

        $previous = $pool->setCallbackWrapper($lock);

        try {
            return $call();
        } finally {
            $pool->setCallbackWrapper($previous);
        }
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
            // without a pool we could not read back what the holder produced,
            // so there would be nothing to wait for
            return $callback($item, $save);
        }

        $key = $item->getKey();
        [$lockKey, $channel] = $this->keysFor($key);
        $token = bin2hex(random_bytes(16));
        $until = Deadline::beforeMaxExecutionTime();

        while (true) {
            try {
                $acquired = $this->acquire($lockKey, $token);
            } catch (\Exception $e) {
                // a cache must never be the reason an application stops serving
                $logger?->warning('MemoLock unreachable, computing item "{key}" unprotected: {message}', ['key' => $key, 'message' => $e->getMessage()]);

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
                    // wake the herd whether we produced a value or threw: a
                    // failed computation must not leave everyone blocked
                    $this->release($lockKey, $channel, $token, $logger);
                }
            }

            $logger?->info('Item "{key}" is locked, waiting for the holder', ['key' => $key]);
            $this->wait($lockKey, $channel, $logger, $until);

            if (null !== $until && microtime(true) >= $until) {
                // max_execution_time is a second away: compute now, unprotected,
                // rather than be killed waiting - the choice LockRegistry makes
                $logger?->warning('max_execution_time is near, computing item "{key}" unprotected', ['key' => $key]);

                return $callback($item, $save);
            }

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
    }

    private function acquire(string $lockKey, string $token): bool
    {
        return (bool) $this->redis->set($lockKey, $token, ['NX', 'PX' => $this->lockTtlMs]);
    }

    /**
     * The keep-alive handed to whichever closure holds the lock, so that work
     * which may outrun `$lockTtlMs` can push its expiry out as it goes.
     */
    private function keepAlive(string $key, string $lockKey, string $token): KeepAlive
    {
        return new KeepAlive($this->redis, $key, $lockKey, $token, $this->lockTtlMs);
    }

    /**
     * Asks whether the thing is there yet.
     *
     * Through a method rather than calling the closure inline, because the
     * answer is expected to *change*: the whole point of waiting is that
     * somebody else produces it meanwhile. Called inline, static analysis
     * concludes - quite reasonably, for any other closure - that one which
     * answered null once answers null always.
     */
    private static function look(\Closure $exists): mixed
    {
        return $exists();
    }

    /**
     * The lock and its wake channel, carrying one hash tag between them so a
     * cluster keeps the pair on one node.
     *
     * @return array{string, string}
     */
    private function keysFor(string $key): array
    {
        $tag = '{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';

        return [self::LOCK_PREFIX.$tag, self::WAKE_PREFIX.$tag];
    }

    /**
     * Deletes the lock only if we still hold it - a lock that expired and was
     * taken by someone else must not be released by the previous owner - and
     * wakes the waiters. The wake-up goes out either way: a waiter re-reads
     * rather than trusts it, so one too many costs a read and one too few
     * costs a slice.
     */
    private function release(string $lockKey, string $channel, string $token, ?LoggerInterface $logger): void
    {
        try {
            RedisCommand::deleteIfEqual($this->redis, $lockKey, $token);
            $this->redis->publish($channel, self::WAKE_MESSAGE);
        } catch (\Exception $e) {
            // the lock expires on its own; waiters notice within a slice
            $logger?->warning('MemoLock could not release "{key}": {message}', ['key' => $lockKey, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Blocks until the holder releases, a slice at a time, for at most
     * $waitTimeoutMs.
     *
     * Before every SUBSCRIBE the lock is looked at: one that is already gone
     * has nobody left to wake us, so a wake-up published in the window before
     * we subscribed is not waited for at all. One that is still held is
     * waited for - by message, for a slice, then looked at again.
     */
    private function wait(string $lockKey, string $channel, ?LoggerInterface $logger, ?float $until = null): void
    {
        $deadline = microtime(true) + $this->waitTimeoutMs / 1000;

        // never past max_execution_time (see Deadline): a waiter killed
        // mid-wait serves nobody
        if (null !== $until) {
            $deadline = min($deadline, $until);
        }

        if (null === $this->subscriberFactory) {
            $this->poll($lockKey, $deadline);

            return;
        }

        while (true) {
            $remaining = $this->remaining($lockKey);

            if (null === $remaining) {
                // gone, or unreachable: either way there is nothing to wait for
                return;
            }

            $slice = min(($deadline - microtime(true)) * 1000, self::WAKE_SLICE_MS);

            if ($remaining > 0) {
                // a crashed holder never publishes: never wait much longer than
                // its lock can live
                $slice = min($slice, $remaining + 25);
            }

            if ($slice <= 0) {
                return;
            }

            if ($this->listen($channel, $slice, $logger) || microtime(true) >= $deadline) {
                return;
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
     * failed.
     */
    private function listen(string $channel, float $sliceMs, ?LoggerInterface $logger): bool
    {
        if (null === $factory = $this->subscriberFactory) {
            return false;
        }

        try {
            $subscriber = $this->subscriber ??= $factory();
        } catch (\Exception $e) {
            $logger?->debug('MemoLock could not open a subscriber connection: {message}', ['message' => $e->getMessage()]);

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
        } catch (\Exception $e) {
            // the slice passed without a message, or Redis went away
            $logger?->debug('MemoLock stopped waiting on "{channel}": {message}', ['channel' => $channel, 'message' => $e->getMessage()]);
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

    /**
     * Fallback for when no second connection is available: watch the lock key
     * until it goes away. One origin call still, but the wake-up costs up to
     * $pollIntervalMs instead of arriving as a message.
     */
    private function poll(string $lockKey, float $deadline): void
    {
        while (microtime(true) < $deadline) {
            usleep($this->pollIntervalMs * 1000);

            try {
                if (!$this->redis->exists($lockKey)) {
                    return;
                }
            } catch (\Exception) {
                return;
            }
        }
    }
}

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
use Artigo\Cache\Stale\Election;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Lets one request compute a missing cache value while the others wait for
 * it, even when the application runs on many servers.
 *
 * When a cached value expires, every request that arrives before it is
 * computed again misses it too, and each one would compute it. Symfony's
 * LockRegistry stops that with flock() on local files: one request computes,
 * the others wait. But a local file is only seen by one machine. Example:
 * twelve servers each get one request for an expired report at the same
 * moment. With LockRegistry each server computes the report once, so the
 * database runs the same query twelve times.
 *
 * This class keeps the lock in Redis instead, where every server sees it.
 * The first request takes the lock and computes the value. The other eleven
 * wait, then read the value the first one stored, so the query runs once.
 * A waiter does not check the lock over and over. It listens on a Redis
 * Pub/Sub channel, and the request that computed the value sends a message
 * there when it is done, so the waiters wake up at once.
 *
 * It plugs in through the callback wrapper that every Symfony pool takes:
 *
 *     $pool = new RedisAdapter($redis);
 *     $pool->setCallbackWrapper(MemoLock::fromDsn('redis://127.0.0.1'));
 *
 * No subclassing and no fork are needed, and it works with any pool -
 * filesystem, chained, tag-aware - not only Redis ones. Setting the wrapper
 * yourself also turns locking on under the CLI, where Symfony turns it off by
 * default. That is what lets the benchmarks measure it.
 *
 * The lock is four plain Redis commands and no script. `SET NX PX` takes it.
 * `SET ... IFEQ` extends it, but only while it still holds our token.
 * `DELEX ... IFEQ` deletes it on the same condition. `PUBLISH` wakes the
 * waiters. The two IFEQ forms need Redis 8.4, and Valkey calls the delete
 * `DELIFEQ`. A server that lacks them - Redis before 8.4, Valkey before 9 -
 * refuses the command once, and from then on that connection uses a small
 * compare-and-set script instead (see RedisCommand).
 *
 * A waiter never waits into max_execution_time. A second before the limit it
 * stops waiting and computes the value without the lock, as LockRegistry does
 * (see Deadline). Otherwise PHP could kill the request while it waits.
 *
 * **This class does not do probabilistic early expiration**, and it ignores
 * $beta. Recomputing a value a little early, at random, in the hope that the
 * crowd of requests never forms, is the other answer to this problem, and
 * this class exists because that answer is not enough. Symfony also decides
 * it in ContractsTrait before it calls this wrapper, so there is nothing here
 * to switch off.
 *
 * The one $beta this class reads is INF. It is not a guess: it means
 * "recompute this now". It is handled the way `LockRegistry` handles it. The
 * caller waits for the lock like any other waiter, then takes it and
 * computes, instead of reading what the holder stored. If a forced recompute
 * skipped the lock, many of them at once would cause the very stampede this
 * class is here to prevent.
 *
 * Before each SUBSCRIBE a waiter checks the lock, and it listens for at most
 * WAKE_SLICE_MS at a time. If the holder was quick and sent its message
 * before the waiter subscribed, the waiter misses it, but loses at most one
 * slice, not the whole $waitTimeoutMs. The subscriber connection is opened
 * once per instance and kept.
 */
final class MemoLock implements Election
{
    private const LOCK_PREFIX = 'memolock:lock:';
    private const WAKE_PREFIX = 'memolock:wake:';

    /**
     * The lock that picks who refreshes a stale value (see tryLock()) is
     * separate from the item lock. Otherwise, while one request holds the item
     * lock to compute the same key after a miss, nobody could be picked to
     * refresh it.
     */
    private const REFRESH_PREFIX = 'memolock:refresh:';

    /**
     * The message sent on the wake channel. Its content is never checked: a
     * waiter reads the pool again instead of trusting a message, because it
     * cannot tell who sent it.
     */
    private const WAKE_MESSAGE = '1';

    /**
     * How many times once() and exclusively() wait and try again before they
     * give up. That is enough to outlast a holder that died while holding the
     * lock, and few enough that a lock nobody releases does not keep the
     * caller looping forever.
     */
    private const ATTEMPTS = 10;

    /**
     * How long one SUBSCRIBE listens before the waiter checks the lock again.
     * If the wake-up message went out before the waiter subscribed, the waiter
     * notices within this time instead of at the end of the whole wait. It is
     * also how often LockRegistry checks its lock.
     */
    private const WAKE_SLICE_MS = 100;

    /**
     * The second connection, used only to SUBSCRIBE while waiting. It is
     * opened on the first wait and kept for the next ones.
     */
    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster|null $subscriber = null;

    /**
     * @param \Closure():(\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster)|null $subscriberFactory opens a
     *                                                                                              *second* connection for SUBSCRIBE. A subscribed connection cannot send any other command, so
     *                                                                                              it cannot be the one that takes the lock. Without it, waiters check the lock key every
     *                                                                                              $pollIntervalMs instead: the value is still computed once, but waiters wake up later.
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
     * Opens both connections from one DSN, through Symfony's own connection
     * factory. So every DSN that Symfony understands (cluster, sentinel,
     * Relay, TLS, auth) works here too.
     */
    public static function fromDsn(string $dsn, int $lockTtlMs = 30_000, int $waitTimeoutMs = 5_000): self
    {
        // lazy: nothing connects until the first command. If Redis is down,
        // that command fails and the value is computed without the lock.
        // Creating the service must never fail because Redis is down
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
     * Runs $run while holding the lock on $key, so that no other process runs
     * it at the same time.
     *
     *     $lock->exclusively('import:companies', function (): void {
     *         $this->importCompanies();
     *     });
     *
     * Here a waiter wants the lock itself, not a value someone else made. So
     * when it is woken, it tries to take the lock again. The wake-up message
     * is why this method exists next to `symfony/lock`. Example: eight workers
     * each hold a 200 ms section, one after the other. The handovers cost
     * 10 ms in total here, against 105 ms when the waiters poll.
     *
     * **What `symfony/lock` still does better:** it releases the lock when the
     * lock object is destroyed. So if the process calls exit() or dies with a
     * fatal error in the middle of the section, the lock is handed back at
     * shutdown. A finally block does not run in that case. Here the lock then
     * stays until its TTL runs out, and that is also the longest a waiter
     * waits for it. Work that may take longer than `$lockTtlMs` is handed a
     * {@see KeepAlive} to extend the lock.
     *
     * Unlike everything else in this class, this method **throws** instead of
     * running without the lock. It throws {@see LockUnavailable} when the lock
     * cannot be reached, when it is still held after every attempt, or when
     * max_execution_time is too close to wait any longer. A caller who asked
     * to run alone has no use for a run beside the holder.
     *
     * @template T
     *
     * @param \Closure(KeepAlive): T $run
     *
     * @return T
     *
     * @throws LockUnavailable when the lock cannot be reached or is never released
     * @throws \Throwable      whatever $run throws, after the lock is released
     */
    public function exclusively(string $key, \Closure $run, ?LoggerInterface $logger = null): mixed
    {
        [$lockKey, $channel] = $this->keysFor($key);
        $token = bin2hex(random_bytes(16));
        $until = Deadline::beforeMaxExecutionTime();

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            try {
                $acquired = $this->acquire($lockKey, $token);
            } catch (\Throwable $e) {
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
                // max_execution_time is a second away and the lock is still
                // held. The caller asked to run alone, so throw instead of
                // running beside the holder
                throw new LockUnavailable(\sprintf('"%s" is still held, and max_execution_time is a second away.', $key));
            }
        }

        throw new LockUnavailable(\sprintf('"%s" was still held after %d attempts.', $key, self::ATTEMPTS));
    }

    /**
     * Makes something once, while the other callers wait for the result
     * instead of for the lock.
     *
     * Use it for a thing that is made once: a thumbnail, a generated file, a
     * provisioned resource. Example: four requests ask for a thumbnail that
     * does not exist yet. One of them renders it. The other three wait until
     * it exists and then use it, instead of taking turns to render it again.
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
     * **This is not a mutex, and symfony/lock is the better mutex.** It
     * refreshes a held lock, releases it when the lock object is destroyed,
     * and works with a dozen stores. Use symfony/lock for "only one worker may
     * run this at a time". Use this method when the waiters want the
     * *result*. Its one advantage is that waiters are woken by a message
     * instead of by polling: 4 ms against 101 ms, where the stampede benchmark
     * could measure it.
     *
     * $exists is called before any lock is taken. So a caller that arrives
     * after the work is done never asks Redis for a lock at all. A waiter
     * calls $exists again after every wake-up. Returning null means "not
     * there yet".
     *
     * If the lock cannot be reached, is still held after every attempt, or
     * max_execution_time is close, $make runs without the lock: the thing may
     * be made twice, but the caller still gets it.
     *
     * @param \Closure():mixed           $exists the thing, or null if it is not there yet
     * @param \Closure(KeepAlive): mixed $make   makes the thing and returns it
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
            } catch (\Throwable $e) {
                // Redis cannot be reached. A lock that cannot be taken must
                // not stop the work, so make the thing without it
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
                // max_execution_time is a second away: make it now without the
                // lock, rather than be killed by PHP while still waiting
                $logger?->warning('max_execution_time is near, producing "{key}" unprotected', ['key' => $key]);

                return $make(KeepAlive::unheld($key));
            }

            if (null !== $produced) {
                $logger?->info('"{key}" was there after the lock was released', ['key' => $key]);

                return $produced;
            }

            // still not there: the holder died, finished without making it,
            // or is still working. Go round again: take the lock, or wait
            $logger?->info('"{key}" still missing after the lock was released, retrying', ['key' => $key]);
        }

        $logger?->warning('Gave up waiting for "{key}", producing it anyway', ['key' => $key]);

        return $make(KeepAlive::unheld($key));
    }

    /**
     * A copy of this lock with different timings, for work that is unlike the
     * rest. This lock itself is not changed.
     *
     * A slow computation, such as an image render or a call to an external
     * API, needs a lock that lasts longer than it does. If the lock expires
     * while the holder is still working, a waiter takes it and starts the same
     * work. Pair the copy with {@see around()} to use it for a single call.
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
     * Runs one call with this lock as the pool's callback wrapper, then puts
     * the previous wrapper back.
     *
     *     $value = $lock->with(lockTtlMs: 30_000)->around(
     *         $pool,
     *         static fn () => $pool->get('external-api', $resolver),
     *     );
     *
     * Symfony's `get()` has no place for a per-call setting. Its signature is
     * `($key, $callback, $beta, &$metadata)`, and $beta controls early
     * expiration, not locking. But `setCallbackWrapper()` returns the wrapper
     * it replaced, so one call can borrow a different lock and give the old
     * one back. The old one is put back in a finally block, so it is restored
     * even when the call throws. That is the part people forget when they
     * write this by hand.
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
            // a pool without getItem() cannot be read after the wait, so a
            // waiter could never see what the holder stored: just compute
            return $callback($item, $save);
        }

        $key = $item->getKey();
        [$lockKey, $channel] = $this->keysFor($key);
        $token = bin2hex(random_bytes(16));
        $until = Deadline::beforeMaxExecutionTime();

        while (true) {
            try {
                $acquired = $this->acquire($lockKey, $token);
            } catch (\Throwable $e) {
                // Redis cannot be reached: compute without the lock, because a
                // cache must never be the reason a request fails
                $logger?->warning('MemoLock unreachable, computing item "{key}" unprotected: {message}', ['key' => $key, 'message' => $e->getMessage()]);

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
                    // release the lock and wake the waiters, whether the
                    // callback returned or threw. A failed computation must
                    // not leave the others waiting until the lock expires
                    $this->release($lockKey, $channel, $token, $logger);
                }
            }

            $logger?->info('Item "{key}" is locked, waiting for the holder', ['key' => $key]);
            $this->wait($lockKey, $channel, $logger, $until);

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
            // the wait timed out while it still works. Go round again: take
            // the lock, or wait once more
            $logger?->info('Item "{key}" still missing after the lock was released, retrying', ['key' => $key]);
        }
    }

    /**
     * Picks the one process that refreshes a stale value (see StaleCache).
     * It is a single SET NX on a lock of its own: whoever sets it refreshes,
     * and the others do not wait, they serve the stale value. The closure
     * returned deletes the lock, but only while it still holds our token.
     */
    public function tryLock(string $key, int $ttlMs): ?\Closure
    {
        $lockKey = self::REFRESH_PREFIX.self::tagFor($key);
        $token = bin2hex(random_bytes(16));

        if (!$this->redis->set($lockKey, $token, ['NX', 'PX' => $ttlMs])) {
            return null;
        }

        return function () use ($lockKey, $token): void {
            RedisCommand::deleteIfEqual($this->redis, $lockKey, $token);
        };
    }

    private function acquire(string $lockKey, string $token): bool
    {
        return (bool) $this->redis->set($lockKey, $token, ['NX', 'PX' => $this->lockTtlMs]);
    }

    /**
     * Builds the KeepAlive handed to the closure that holds the lock. Work
     * that may take longer than `$lockTtlMs` calls it as it goes, to push the
     * lock's expiry further out.
     */
    private function keepAlive(string $key, string $lockKey, string $token): KeepAlive
    {
        return new KeepAlive($this->redis, $key, $lockKey, $token, $this->lockTtlMs);
    }

    /**
     * Asks whether the thing is there yet.
     *
     * This is a method, not an inline call of the closure, because the answer
     * is expected to *change*: while we wait, somebody else makes the thing.
     * If the closure were called inline, static analysis would conclude that
     * a closure which returned null once always returns null. That is right
     * for most closures, but not for this one.
     */
    private static function look(\Closure $exists): mixed
    {
        return $exists();
    }

    /**
     * The names of the lock key and of its wake channel. Both carry the same
     * hash tag, so a Redis Cluster keeps the pair on one node.
     *
     * @return array{string, string}
     */
    private function keysFor(string $key): array
    {
        $tag = self::tagFor($key);

        return [self::LOCK_PREFIX.$tag, self::WAKE_PREFIX.$tag];
    }

    /**
     * A Redis Cluster hash tag for the cache key: a short hash of the key in
     * curly braces. Every Redis key built from it lands on the same node, and
     * the tag stays short however long the cache key is or whatever it holds.
     */
    private static function tagFor(string $key): string
    {
        return '{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
    }

    /**
     * Deletes the lock, but only if it still holds our token, and wakes the
     * waiters.
     *
     * The token check matters when our lock expired and another process took
     * it: we must not delete their lock. The wake-up message goes out even
     * when the delete did nothing. A waiter does not trust the message, it
     * reads the pool again. So an extra message costs one read, and a missing
     * one costs a waiter one slice (WAKE_SLICE_MS).
     *
     * It never throws. It runs in a finally block, where anything it threw,
     * even an \Error from the Redis client, would replace the value just
     * computed.
     */
    private function release(string $lockKey, string $channel, string $token, ?LoggerInterface $logger): void
    {
        try {
            RedisCommand::deleteIfEqual($this->redis, $lockKey, $token);
            $this->redis->publish($channel, self::WAKE_MESSAGE);
        } catch (\Throwable $e) {
            // the lock expires on its own, and waiters notice within one slice
            $logger?->warning('MemoLock could not release "{key}": {message}', ['key' => $lockKey, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Waits until the holder releases the lock, for at most $waitTimeoutMs,
     * and never past $until.
     *
     * It waits in slices. Before each SUBSCRIBE it checks the lock. If the
     * lock is already gone, nobody is left to send a message, so it returns at
     * once. That also covers a wake-up that went out before we subscribed. If
     * the lock is still held, it listens for a message for one slice, then
     * checks the lock again. Without a subscriber connection it polls the
     * lock instead (see poll()).
     */
    private function wait(string $lockKey, string $channel, ?LoggerInterface $logger, ?float $until = null): void
    {
        $deadline = microtime(true) + $this->waitTimeoutMs / 1000;

        // never past max_execution_time (see Deadline): a waiter that PHP
        // kills in the middle of the wait serves nobody
        if (null !== $until) {
            $deadline = min($deadline, $until);
        }

        // no second connection: check the lock key on a timer instead
        if (null === $this->subscriberFactory) {
            $this->poll($lockKey, $deadline);

            return;
        }

        while (true) {
            $remaining = $this->remaining($lockKey);

            if (null === $remaining) {
                // the lock is gone, or Redis cannot be asked: either way there
                // is nothing to wait for
                return;
            }

            // one slice, or less when the deadline is closer
            $slice = min(($deadline - microtime(true)) * 1000, self::WAKE_SLICE_MS);

            if ($remaining > 0) {
                // a holder that crashed never sends the message, so do not
                // listen much longer than its lock has left to live
                $slice = min($slice, $remaining + 25);
            }

            if ($slice <= 0) {
                return;
            }

            // stop when the message came or the time is up; otherwise check
            // the lock again
            if ($this->listen($channel, $slice, $logger) || microtime(true) >= $deadline) {
                return;
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
        } catch (\Throwable) {
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
     * connection failed.
     */
    private function listen(string $channel, float $sliceMs, ?LoggerInterface $logger): bool
    {
        if (null === $factory = $this->subscriberFactory) {
            return false;
        }

        try {
            $subscriber = $this->subscriber ??= $factory();
        } catch (\Throwable $e) {
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
            // the first message ends the wait. ext-redis ignores an exception
            // thrown from this callback and keeps listening, so the callback
            // unsubscribes, which is the only way to stop it
            $subscriber->subscribe([$channel], static function (\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $connection, string $subscribed): void {
                $connection->unsubscribe([$subscribed]);
            });

            return true;
        } catch (\Throwable $e) {
            // the read timed out because no message came within the slice, or
            // Redis went away
            $logger?->debug('MemoLock stopped waiting on "{channel}": {message}', ['channel' => $channel, 'message' => $e->getMessage()]);
        }

        // When the read times out, ext-redis leaves the connection
        // subscribed, so we unsubscribe before the next wait uses it. Relay
        // has already ended the subscription itself, and answers the
        // unsubscribe with a "Not subscribed" warning, not an exception. The
        // error handler set here silences that warning.
        set_error_handler(static fn (): bool => true);

        try {
            $subscriber->unsubscribe([$channel]);
        } catch (\Throwable) {
            // the connection cannot be used any more: close it, and the next
            // wait opens a new one
            $this->subscriber = null;

            try {
                $subscriber->close();
            } catch (\Throwable) {
            }
        } finally {
            restore_error_handler();
        }

        return false;
    }

    /**
     * Used when there is no second connection: checks every $pollIntervalMs
     * whether the lock key still exists, until it is gone or $deadline
     * passes. The value is still computed once, but a waiter notices the
     * release up to $pollIntervalMs late instead of being woken by a message.
     */
    private function poll(string $lockKey, float $deadline): void
    {
        while (microtime(true) < $deadline) {
            usleep($this->pollIntervalMs * 1000);

            try {
                if (!$this->redis->exists($lockKey)) {
                    return;
                }
            } catch (\Throwable) {
                // Redis cannot be asked: stop waiting
                return;
            }
        }
    }
}

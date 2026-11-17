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
use Artigo\Cache\Stale\Election;
use Artigo\Cache\Stale\FlockElection;
use Artigo\Cache\Stale\Record;
use Artigo\Cache\Stale\SoftWrapper;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Serves a cached value for a while after it expires, while one process
 * computes the new one, so that no request has to wait for it.
 *
 * A lock (MemoLock, FleetLock) makes sure an expired value is computed only
 * once, but every request that arrives meanwhile still waits for that one
 * computation. This pool removes the wait: for $staleSeconds after a value's
 * lifetime ends, it serves the old value at once and lets one process
 * refresh it. Example: an article is cached for 300 seconds, and
 * $staleSeconds is 60. At second 310 twelve requests read it. With a
 * RefreshQueue as the deferrer, all twelve get the old article at once, and
 * one process loads the new one after its response has gone out.
 *
 *     $pool = new StaleCache($redisPool, staleSeconds: 60, staleIfErrorSeconds: 600, election: $memoLock);
 *     $pool->setDeferrer($refreshQueue);   // refresh after the response
 *
 *     $pool->get('article-42', function (ItemInterface $item): Article {
 *         $item->expiresAfter(300);
 *
 *         return $this->load(42);
 *     });
 *
 * This has to wrap the pool; a callback wrapper would not do. A callback
 * wrapper is only called on a miss, and Symfony stores only one expiry per
 * value. So this pool stores a Record in the inner pool: the value and its
 * three times. The inner pool keeps it until the last of them. The lifetime
 * the callback sets is when the value turns stale. A read finds the value in
 * one of four states:
 *
 * - **Fresh**: served.
 * - **Stale** (up to $staleSeconds past its lifetime): served at once, with
 *   METADATA_STALE set, and one refresh is scheduled. One process wins the
 *   Election and refreshes; the others serve the stale value and move on.
 *   With a deferrer the refresh runs after the response (see RefreshQueue).
 *   Without one it runs at once, and the caller gets the new value.
 * - **Kept for errors** (up to $staleIfErrorSeconds after that): computed
 *   now, like a miss. If that throws, the old value is served, and the
 *   failure is logged and remembered. While it is remembered, the old value
 *   is served at once, as a stale one is, and one process tries again every
 *   $errorRetrySeconds, until a computation succeeds.
 * - **Gone**: a miss, computed by the inner pool and its callback wrapper.
 *
 * A refresh is the inner pool's forced get() (INF), so it goes through the
 * inner pool's lock. On versioned-cache it is also treated like a miss: if
 * the key is deleted while the refresh computes, the refresh's write is
 * refused. A refresh that throws keeps the stale value. An invalidated value
 * is never served stale, because the inner pool answers a miss for it.
 *
 * A computation that failed is remembered in the inner pool, under a key of
 * its own (FAILED_PREFIX and a hash of the value's key), for
 * $errorRetrySeconds and the time a refresh may hold its election. Example:
 * the database goes down at 12:00:00 and a report is past its stale time.
 * The request that reads it computes, fails after 500 ms, and gets the old
 * report. Nine requests that waited behind it get the old report as soon as
 * it has failed, and compute nothing. For the next second every request
 * gets the old report at once. Then one request is elected to try again,
 * after its response if there is a deferrer, and so on, once a second, until
 * the database answers. So past the stale time a failing origin is asked
 * once per $errorRetrySeconds, not once per request, and no request waits
 * for a computation that has just failed.
 *
 * A refresh of a stale value that fails is remembered the same way. Inside
 * the stale time that changes nothing: a stale read is served at once and
 * may refresh, as before. But when the stale time ends during an outage, the
 * failure is already known, and nobody has to compute to find out. With no
 * failure remembered, the kept value is never served: a read past the stale
 * time computes, and the requests beside it wait for the new value.
 *
 * The inner pool is this pool's storage. What it holds are Records, so read
 * them through this pool, not directly. This pool's PSR-6 methods are
 * strict: getItem() on a stale value is a miss, and save() stores the value
 * with this pool's times.
 *
 * With $soft, a value invalidated by tag is served stale too, counted from
 * the invalidation. That needs an inner pool that keeps what a tag
 * invalidation reached (versioned-cache with softGraceSeconds), and an
 * invalidation that was not a hard one. Set the inner pool's callback
 * wrapper (its lock) before handing the pool over: this pool puts its own
 * wrapper in front of it.
 */
final class StaleCache implements AdapterInterface, TagAwareCacheInterface, PruneableInterface, ResettableInterface, LoggerAwareInterface
{
    /**
     * Set in $metadata by get() when the value served is stale.
     */
    public const METADATA_STALE = 'stale';

    /**
     * The inner pool's key of a remembered failure starts with this; a hash
     * of the value's key follows.
     */
    public const FAILED_PREFIX = 'stale.failed.';

    private readonly Election $election;

    /** @var (\Closure(\Closure(): void, string): mixed)|null takes the refresh and its key */
    private ?\Closure $deferrer = null;

    private ?LoggerInterface $logger = null;

    private readonly string $poolHash;

    /** @var \Closure(CacheItem): (float|int|null) */
    private readonly \Closure $expiryOf;

    /** @var \Closure(string, CacheItemInterface, string, bool, ?float): CacheItem */
    private readonly \Closure $createItem;

    /** @var \Closure(CacheItem): array{string, mixed, float|int|null, mixed, ?CacheItemInterface, ?string} */
    private readonly \Closure $unpackItem;

    /**
     * @param CacheItemPoolInterface&CacheInterface $inner             where the Records are kept
     * @param int                                   $defaultLifetime   the lifetime in seconds of a value whose callback sets none; 0 means no lifetime, so such a value never turns stale
     * @param bool                                  $soft              whether the values this pool writes are served stale after a tag invalidation too
     * @param int                                   $errorRetrySeconds how long after a computation failed the next one may start; with 0 the next read may try again
     */
    public function __construct(
        private readonly CacheItemPoolInterface&CacheInterface $inner,
        private readonly int $staleSeconds,
        private readonly int $staleIfErrorSeconds = 0,
        ?Election $election = null,
        private readonly int $defaultLifetime = 0,
        private readonly int $refreshLockTtlMs = 30_000,
        private readonly bool $soft = false,
        private readonly int $errorRetrySeconds = 1,
    ) {
        foreach (['staleSeconds' => $staleSeconds, 'staleIfErrorSeconds' => $staleIfErrorSeconds, 'defaultLifetime' => $defaultLifetime, 'errorRetrySeconds' => $errorRetrySeconds] as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(\sprintf('Argument "$%s" must not be negative, %d given.', $name, $value));
            }
        }

        if ($refreshLockTtlMs < 1) {
            throw new InvalidArgumentException(\sprintf('Argument "$refreshLockTtlMs" must be a positive number of milliseconds, %d given.', $refreshLockTtlMs));
        }

        $this->election = $election ?? new FlockElection();
        $this->poolHash = spl_object_hash($this);

        if ($soft) {
            $this->serveSoftly($inner);
        }

        // CacheItem's properties are protected, meant for Symfony's own pools.
        // Closures bound to the CacheItem class can read and set them too,
        // which is how this pool builds items and reads them back
        $this->expiryOf = \Closure::bind(static fn (CacheItem $item): float|int|null => $item->expiry, null, CacheItem::class);
        $this->createItem = \Closure::bind(static function (string $key, CacheItemInterface $innerItem, string $poolHash, bool $taggable, ?float $expiry): CacheItem {
            $item = new CacheItem();
            $item->key = $key;
            $item->innerItem = $innerItem;
            $item->poolHash = $poolHash;
            $item->isTaggable = $taggable;

            $record = $innerItem->isHit() ? $innerItem->get() : null;

            // strict: only a fresh value is a hit; a stale one is a miss here
            if ($record instanceof Record && $record->isFresh(microtime(true))) {
                $item->value = $record->value;
                $item->isHit = true;

                if (null !== $expiry) {
                    $item->metadata[ItemInterface::METADATA_EXPIRY] = $expiry;
                }
            }

            return $item;
        }, null, CacheItem::class);
        $this->unpackItem = \Closure::bind(static fn (CacheItem $item): array => [
            $item->key,
            $item->value,
            $item->expiry,
            $item->newMetadata[CacheItem::METADATA_TAGS] ?? [],
            $item->innerItem,
            $item->poolHash,
        ], null, CacheItem::class);
    }

    /**
     * Sets what runs a refresh later instead of at once, such as a
     * RefreshQueue. With null, a refresh runs at once, inside the get() that
     * found the value stale.
     *
     * The deferrer is called with the refresh, a \Closure that takes no
     * argument, and a key. The key is a string that is the same for every
     * refresh of one cache key in this pool, and different for any other key
     * or pool. So the deferrer can keep one refresh per key. The key is passed
     * only when the parameter that would receive it accepts a string: a
     * second parameter, or a variadic one, that is untyped or typed string,
     * ?string, mixed, or a union that contains one of them. Any other
     * deferrer, such as one with a single parameter or one whose second
     * parameter is an int, is called with the refresh alone.
     */
    public function setDeferrer(?callable $deferrer): void
    {
        if (null === $deferrer) {
            $this->deferrer = null;

            return;
        }

        $deferrer = $deferrer(...);

        // check first, because passing the key to a deferrer that cannot take
        // it fails: a built-in PHP function rejects an extra argument, and
        // with strict types a parameter typed int, for example, rejects a
        // string
        $this->deferrer = self::takesKey(new \ReflectionFunction($deferrer))
            ? $deferrer
            : static fn (\Closure $refresh): mixed => $deferrer($refresh);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param array<mixed>|null $metadata
     */
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        // beta 0 switches off the inner pool's early expiration: it would act
        // on how long the inner pool keeps the Record, not on when the value
        // turns stale. A forced get (INF) stays forced
        $record = $this->inner->get($key, $this->wrap($key, $callback, false), \INF === $beta ? \INF : 0.0, $metadata);

        if (!$record instanceof Record) {
            // the key holds something that is not a Record, for example a
            // value written to the inner pool directly: compute the value and
            // store a Record over it
            $record = $this->inner->get($key, $this->wrap($key, $callback, false), \INF, $metadata);

            // still not a Record: hand back what the inner pool answered
            if (!$record instanceof Record) {
                return $record;
            }
        }

        $now = microtime(true);

        // fresh: serve it
        if ($record->isFresh($now)) {
            self::describe($metadata, $record, false);

            return $record->value;
        }

        // stale, but still within $staleSeconds: schedule one refresh. If it
        // ran at once (no deferrer) and came back with a fresh value, serve
        // that one; otherwise serve the stale value
        if ($record->isServable($now)) {
            $refreshed = $this->schedule($key, $callback);

            if (null !== $refreshed) {
                self::describe($metadata, $refreshed, false);

                return $refreshed->value;
            }

            self::describe($metadata, $record, true);

            return $record->value;
        }

        $kept = $record->isKeptForErrors($now) ? $record : null;

        // past the stale time, kept for errors, and computing it failed a
        // moment ago, here or in another process: serve the old value at
        // once, as a stale one is served. Once $errorRetrySeconds have
        // passed, one process tries again (see refresh())
        if (null !== $kept && null !== $failedAt = $this->failedAt($key, $kept)) {
            $refreshed = $now - $failedAt < $this->errorRetrySeconds ? null : $this->schedule($key, $callback, $kept);

            if (null !== $refreshed) {
                self::describe($metadata, $refreshed, false);

                return $refreshed->value;
            }

            self::describe($metadata, $kept, true);

            return $kept->value;
        }

        // past the stale time: compute it now, like a miss. INF forces the
        // computation, because the inner pool still holds the old Record. If
        // another process stored a fresh value while this one waited for the
        // inner pool's lock, wrap() takes that one instead. If another
        // process computed and failed meanwhile, wrap() hands $kept back
        try {
            $computed = $this->inner->get($key, $this->wrap($key, $callback, true, $kept, null !== $kept), \INF, $metadata);
        } catch (\Throwable $e) {
            // the computation failed: serve the old value if it is still kept
            // for errors, otherwise let the exception through
            if (null === $kept) {
                throw $e;
            }

            $this->logger?->error('Computing "{key}" failed; its stale value is served: {message}', ['key' => $key, 'message' => $e->getMessage(), 'exception' => $e]);
            self::describe($metadata, $kept, true);

            return $kept->value;
        }

        if (null !== $kept && $computed === $kept) {
            // the process this one waited for failed, and nothing was
            // computed here
            self::describe($metadata, $kept, true);

            return $kept->value;
        }

        if (!$computed instanceof Record) {
            return $computed;
        }

        self::describe($metadata, $computed, false);

        return $computed->value;
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    /**
     * Passes the invalidation on to the inner pool, together with $hard. A
     * pool that has both soft and hard invalidations reads $hard; any other
     * pool ignores the extra argument.
     */
    public function invalidateTags(array $tags, bool $hard = false): bool
    {
        if (!$this->inner instanceof TagAwareCacheInterface) {
            return false;
        }

        $invalidate = [$this->inner, 'invalidateTags'];

        // @phpstan-ignore arguments.count (a pool with soft invalidations reads the second argument; any other ignores it)
        return true === $invalidate($tags, $hard);
    }

    public function getItem(mixed $key): CacheItem
    {
        return $this->unwrap((string) $key, $this->inner->getItem($key));
    }

    /**
     * @return iterable<string, CacheItem>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($this->inner->getItems($keys) as $innerItem) {
            if ($innerItem instanceof CacheItemInterface) {
                yield $innerItem->getKey() => $this->unwrap($innerItem->getKey(), $innerItem);
            }
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->inner instanceof AdapterInterface ? $this->inner->clear($prefix) : $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->store($item, false);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->store($item, true);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function prune(): bool
    {
        return $this->inner instanceof PruneableInterface && $this->inner->prune();
    }

    /**
     * Resets the inner pool only. A queued refresh is still valid after the
     * request ends, so nothing is dropped or run here.
     */
    public function reset(): void
    {
        if ($this->inner instanceof ResetInterface) {
            $this->inner->reset();
        }
    }

    /**
     * Builds the callback the inner pool computes with. It calls the user's
     * callback and wraps the result in a Record with this pool's times.
     *
     * With $reuseFresh, it first reads the inner pool. If another process
     * stored a fresh value while this one waited for the inner pool's lock,
     * that value is used as it is, instead of computing it again. With $kept
     * too, the same read looks for a failure. If another process computed
     * and failed while this one waited, $kept is handed back as it is and
     * the callback is not called. So the requests that waited behind a
     * failing computation do not repeat it one after another.
     *
     * With $remember, a callback that throws is remembered (see failed())
     * before the exception goes on. That happens here, inside the inner
     * pool's lock, so the failure is stored before the lock is released and
     * the next request that takes the lock finds it.
     *
     * @return \Closure(ItemInterface, bool): mixed
     */
    private function wrap(string $key, callable $callback, bool $reuseFresh, ?Record $kept = null, bool $remember = false): \Closure
    {
        return function (ItemInterface $item, bool &$save) use ($key, $callback, $reuseFresh, $kept, $remember): mixed {
            if ($reuseFresh) {
                [$record, $failedAt] = $this->look($key, $kept);

                if (null !== $record && $record->isFresh(microtime(true))) {
                    // already stored, with its tags, so there is nothing to save
                    $save = false;

                    return $record;
                }

                if (null !== $kept && null !== $failedAt) {
                    // nothing is computed, so there is nothing to save
                    $save = false;

                    return $kept;
                }
            }

            // compute, and have the inner pool keep the Record for all three
            // of its times (see keep())
            try {
                $value = $callback($item, $save);
            } catch (\Throwable $e) {
                if ($remember) {
                    $this->failed($key);
                }

                throw $e;
            }

            $record = $this->record($value, $item instanceof CacheItem ? ($this->expiryOf)($item) : null);
            $this->keep($item, $record);

            return $record;
        };
    }

    /**
     * Without a deferrer, runs the refresh now and returns the fresh Record,
     * or null when this process did not refresh. With a deferrer, hands the
     * refresh over and returns null. $kept is the value kept for errors when
     * the refresh is the next attempt after a failure (see refresh()).
     */
    private function schedule(string $key, callable $callback, ?Record $kept = null): ?Record
    {
        if (null === $this->deferrer) {
            return $this->refresh($key, $callback, $kept);
        }

        // the key includes this pool's id, so two pools that share one
        // deferrer each keep their own refresh of the same cache key
        ($this->deferrer)(function () use ($key, $callback, $kept): void {
            $this->refresh($key, $callback, $kept);
        }, $key.'@'.$this->poolHash);

        return null;
    }

    /**
     * Refreshes one value, if this process wins the election. It looks at the
     * stored value first, because another process may have refreshed it
     * meanwhile. Only then does it compute, through the inner pool's forced
     * get(). A refresh that fails keeps the stale value and is remembered
     * (see failed()). The election's lock is released either way.
     *
     * With $kept, the refresh is the next attempt after a failure, for a
     * value past its stale time. The look then reads the failure too, in the
     * same round trip. If another process tried again and failed less than
     * $errorRetrySeconds ago, nothing is computed.
     */
    private function refresh(string $key, callable $callback, ?Record $kept = null): ?Record
    {
        try {
            $release = $this->election->tryLock($key, $this->refreshLockTtlMs);
        } catch (\Throwable $e) {
            $this->logger?->warning('Could not elect a refresher for "{key}"; its stale value stays: {message}', ['key' => $key, 'message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }

        if (null === $release) {
            // another process won the election and refreshes it
            return null;
        }

        try {
            // look again: another process may have refreshed it since our read
            [$record, $failedAt] = $this->look($key, $kept);

            if (null !== $record && $record->isFresh(microtime(true))) {
                return $record;
            }

            if (null !== $kept) {
                // another process tried again and failed since our read
                if (null !== $failedAt && microtime(true) - $failedAt < $this->errorRetrySeconds) {
                    return null;
                }

                // remembered from now, so that it stays remembered for as
                // long as this attempt may take. Until it ends, every other
                // request serves the old value and none waits for this one
                $this->failed($key);
            }

            // compute through the inner pool's forced get(), so that the inner
            // pool's lock still applies
            $refreshed = $this->inner->get($key, $this->wrap($key, $callback, false, null, true), \INF);

            return $refreshed instanceof Record ? $refreshed : null;
        } catch (\Throwable $e) {
            $this->logger?->error('Refreshing "{key}" failed; its stale value stays: {message}', ['key' => $key, 'message' => $e->getMessage(), 'exception' => $e]);

            return null;
        } finally {
            try {
                $release();
            } catch (\Throwable $e) {
                // the lock expires on its own
                $this->logger?->warning('Could not release the refresh of "{key}": {message}', ['key' => $key, 'message' => $e->getMessage()]);
            }
        }
    }

    /**
     * Reads the Record stored under $key and, with $kept, the time computing
     * it last failed (see failedAt()), both in one round trip to the inner
     * pool.
     *
     * @return array{?Record, ?float} the Record, or null when the key holds none; the Unix time of the failure, or null when none is remembered
     */
    private function look(string $key, ?Record $kept): array
    {
        $failedKey = self::failedKey($key);
        $record = $failedAt = null;

        foreach ($this->inner->getItems(null === $kept ? [$key] : [$key, $failedKey]) as $itemKey => $item) {
            $value = $item instanceof CacheItemInterface && $item->isHit() ? $item->get() : null;

            if ($failedKey === $itemKey) {
                $failedAt = null === $kept ? null : self::failureOf($value, $kept);
            } elseif ($value instanceof Record) {
                $record = $value;
            }
        }

        return [$record, $failedAt];
    }

    /**
     * When computing the value $kept last failed, as a Unix time, or null
     * when no failure is remembered for it.
     */
    private function failedAt(string $key, Record $kept): ?float
    {
        $item = $this->inner->getItem(self::failedKey($key));

        return $item->isHit() ? self::failureOf($item->get(), $kept) : null;
    }

    /**
     * A remembered failure counts for $kept only if it happened after $kept
     * stopped being fresh. That is when $kept is computed again: by a
     * refresh, or by a read past its stale time. An older failure belongs to
     * a value that was replaced since, so it says nothing about this one.
     * Example: a refresh fails at 12:00:10, and one succeeds at 12:00:12 and
     * stores a value fresh until 12:05:12. The failure of 12:00:10 is still
     * stored, but it is before 12:05:12, so it never counts for the new
     * value.
     */
    private static function failureOf(mixed $failedAt, Record $kept): ?float
    {
        return \is_float($failedAt) && $failedAt >= $kept->freshUntil ? $failedAt : null;
    }

    /**
     * Remembers that computing $key failed just now. It is kept for
     * $errorRetrySeconds and the time a refresh may hold its election. The
     * next attempt starts within that time and remembers itself as it
     * starts, so it never ends to find the failure forgotten. Nothing
     * deletes a remembered failure: it stops counting when a new value is
     * stored (see failureOf()), and expires. If the failure cannot be
     * stored, the next read computes again, as if nothing had failed.
     */
    private function failed(string $key): void
    {
        try {
            $item = $this->inner->getItem(self::failedKey($key))->set(microtime(true));
            $item->expiresAfter($this->errorRetrySeconds + (int) ceil($this->refreshLockTtlMs / 1000));
            $this->inner->save($item);
        } catch (\Throwable $e) {
            $this->logger?->warning('Could not remember that computing "{key}" failed: {message}', ['key' => $key, 'message' => $e->getMessage()]);
        }
    }

    /**
     * The inner pool's key of the failure remembered for $key: a prefix and a
     * hash of $key, so it is a valid key whatever $key holds and however
     * long it is.
     */
    private static function failedKey(string $key): string
    {
        return self::FAILED_PREFIX.hash('xxh128', $key);
    }

    /**
     * Wraps a value in a Record with this pool's times. $expiry is the expiry
     * the callback set, as a Unix time. Without one, $defaultLifetime is used.
     * Without that either, the value never turns stale.
     */
    private function record(mixed $value, float|int|null $expiry): Record
    {
        $freshUntil = match (true) {
            null !== $expiry => (float) $expiry,
            0 < $this->defaultLifetime => microtime(true) + $this->defaultLifetime,
            default => \INF,
        };

        return new Record($value, $freshUntil, $this->staleSeconds, $this->staleIfErrorSeconds, $this->soft);
    }

    /**
     * Puts a SoftWrapper in front of the inner pool's callback wrapper (its
     * lock). On a miss, the SoftWrapper first asks the inner pool for a value
     * that a tag invalidation reached and kept. Anything else still goes
     * through the lock.
     */
    private function serveSoftly(CacheItemPoolInterface&CacheInterface $inner): void
    {
        $setCallbackWrapper = [$inner, 'setCallbackWrapper'];

        if (!SoftWrapper::recognises($inner) || !\is_callable($setCallbackWrapper)) {
            throw new InvalidArgumentException(\sprintf('A soft StaleCache needs an inner pool that keeps what a tag invalidation reached (%s) and takes a callback wrapper, "%s" given.', SoftWrapper::AGED_ITEMS, get_debug_type($inner)));
        }

        // take the current wrapper off, then put it back behind the SoftWrapper
        $previous = $setCallbackWrapper(null);

        if (!\is_callable($previous)) {
            throw new InvalidArgumentException(\sprintf('The callback wrapper of "%s" is not callable.', get_debug_type($inner)));
        }

        $setCallbackWrapper(new SoftWrapper($previous(...)));
    }

    /**
     * Sets the inner item's expiry to the end of the Record's whole life, so
     * the inner pool keeps it as long as it may be served at all. A value
     * without a lifetime never expires.
     */
    private function keep(CacheItemInterface $item, Record $record): void
    {
        if (!is_finite($record->freshUntil)) {
            $item->expiresAt(null);

            return;
        }

        $until = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $record->keptUntil()));

        $item->expiresAt(false === $until ? null : $until);
    }

    private function unwrap(string $key, CacheItemInterface $innerItem): CacheItem
    {
        $record = $innerItem->isHit() ? $innerItem->get() : null;
        $expiry = $record instanceof Record && is_finite($record->freshUntil) ? $record->freshUntil : null;

        return ($this->createItem)($key, $innerItem, $this->poolHash, $this->inner instanceof TagAwareAdapterInterface, $expiry);
    }

    private function store(CacheItemInterface $item, bool $deferred): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }

        [$key, $value, $expiry, $tags, $innerItem, $poolHash] = ($this->unpackItem)($item);
        $tags = \is_array($tags) ? array_values(array_filter($tags, \is_string(...))) : [];

        // reuse the inner item if this pool read it; otherwise read it from
        // the inner pool
        if ($poolHash !== $this->poolHash || null === $innerItem) {
            $innerItem = $this->inner->getItem($key);
        }

        $record = $this->record($value, $expiry);
        $innerItem->set($record);
        $this->keep($innerItem, $record);

        if ([] !== $tags && $this->inner instanceof TagAwareAdapterInterface && $innerItem instanceof ItemInterface) {
            $innerItem->tag($tags);
        }

        return $deferred ? $this->inner->saveDeferred($innerItem) : $this->inner->save($innerItem);
    }

    /**
     * Fills get()'s $metadata: the value's own expiry (not how long the inner
     * pool keeps the Record), and whether the value served was stale.
     *
     * @param array<mixed>|null $metadata
     *
     * @param-out array<mixed> $metadata
     */
    private static function describe(?array &$metadata, Record $record, bool $stale): void
    {
        $metadata ??= [];

        if (is_finite($record->freshUntil)) {
            $metadata[ItemInterface::METADATA_EXPIRY] = $record->freshUntil;
        } else {
            unset($metadata[ItemInterface::METADATA_EXPIRY]);
        }

        if ($stale) {
            $metadata[self::METADATA_STALE] = true;
        } else {
            unset($metadata[self::METADATA_STALE]);
        }
    }

    /**
     * Whether the deferrer can be given the key as its second argument (see
     * setDeferrer()).
     */
    private static function takesKey(\ReflectionFunction $deferrer): bool
    {
        $parameters = $deferrer->getParameters();

        // the second argument lands in the second parameter, or in a
        // variadic first one
        $parameter = $parameters[1] ?? (isset($parameters[0]) && $parameters[0]->isVariadic() ? $parameters[0] : null);

        return null !== $parameter && self::acceptsString($parameter->getType());
    }

    private static function acceptsString(?\ReflectionType $type): bool
    {
        if (null === $type) {
            return true;
        }

        if ($type instanceof \ReflectionNamedType) {
            return \in_array($type->getName(), ['string', 'mixed'], true);
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (self::acceptsString($member)) {
                    return true;
                }
            }
        }

        // an intersection type is made of classes only, so it never takes a
        // string
        return false;
    }
}

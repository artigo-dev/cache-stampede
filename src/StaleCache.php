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
 *   now, like a miss. If that throws, the old value is served and the
 *   failure is logged.
 * - **Gone**: a miss, computed by the inner pool and its callback wrapper.
 *
 * A refresh is the inner pool's forced get() (INF), so it goes through the
 * inner pool's lock. On versioned-cache it is also treated like a miss: if
 * the key is deleted while the refresh computes, the refresh's write is
 * refused. A refresh that throws keeps the stale value. An invalidated value
 * is never served stale, because the inner pool answers a miss for it.
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
     * @param CacheItemPoolInterface&CacheInterface $inner           where the Records are kept
     * @param int                                   $defaultLifetime the lifetime in seconds of a value whose callback sets none; 0 means no lifetime, so such a value never turns stale
     * @param bool                                  $soft            whether the values this pool writes are served stale after a tag invalidation too
     */
    public function __construct(
        private readonly CacheItemPoolInterface&CacheInterface $inner,
        private readonly int $staleSeconds,
        private readonly int $staleIfErrorSeconds = 0,
        ?Election $election = null,
        private readonly int $defaultLifetime = 0,
        private readonly int $refreshLockTtlMs = 30_000,
        private readonly bool $soft = false,
    ) {
        foreach (['staleSeconds' => $staleSeconds, 'staleIfErrorSeconds' => $staleIfErrorSeconds, 'defaultLifetime' => $defaultLifetime] as $name => $value) {
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

        // past the stale time: compute it now, like a miss. INF forces the
        // computation, because the inner pool still holds the old Record. If
        // another process stored a fresh value while this one waited for the
        // inner pool's lock, wrap() takes that one instead
        try {
            $computed = $this->inner->get($key, $this->wrap($key, $callback, true), \INF, $metadata);
        } catch (\Throwable $e) {
            // the computation failed: serve the old value if it is still kept
            // for errors, otherwise let the exception through
            if (!$record->isKeptForErrors($now)) {
                throw $e;
            }

            $this->logger?->warning('Computing "{key}" failed; its stale value is served: {message}', ['key' => $key, 'message' => $e->getMessage(), 'exception' => $e]);
            self::describe($metadata, $record, true);

            return $record->value;
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
     * that value is used as it is, instead of computing it again.
     *
     * @return \Closure(ItemInterface, bool): mixed
     */
    private function wrap(string $key, callable $callback, bool $reuseFresh): \Closure
    {
        return function (ItemInterface $item, bool &$save) use ($key, $callback, $reuseFresh): mixed {
            if ($reuseFresh) {
                $current = $this->inner->getItem($key);
                $record = $current->isHit() ? $current->get() : null;

                if ($record instanceof Record && $record->isFresh(microtime(true))) {
                    // already stored, with its tags, so there is nothing to save
                    $save = false;

                    return $record;
                }
            }

            // compute, and have the inner pool keep the Record for all three
            // of its times (see keep())
            $value = $callback($item, $save);
            $record = $this->record($value, $item instanceof CacheItem ? ($this->expiryOf)($item) : null);
            $this->keep($item, $record);

            return $record;
        };
    }

    /**
     * Without a deferrer, runs the refresh now and returns the fresh Record,
     * or null when this process did not refresh. With a deferrer, hands the
     * refresh over and returns null.
     */
    private function schedule(string $key, callable $callback): ?Record
    {
        if (null === $this->deferrer) {
            return $this->refresh($key, $callback);
        }

        // the key includes this pool's id, so two pools that share one
        // deferrer each keep their own refresh of the same cache key
        ($this->deferrer)(function () use ($key, $callback): void {
            $this->refresh($key, $callback);
        }, $key.'@'.$this->poolHash);

        return null;
    }

    /**
     * Refreshes one value, if this process wins the election. It looks at the
     * stored value first, because another process may have refreshed it
     * meanwhile. Only then does it compute, through the inner pool's forced
     * get(). A refresh that fails keeps the stale value. The election's lock
     * is released either way.
     */
    private function refresh(string $key, callable $callback): ?Record
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
            $current = $this->inner->getItem($key);
            $record = $current->isHit() ? $current->get() : null;

            if ($record instanceof Record && $record->isFresh(microtime(true))) {
                return $record;
            }

            // compute through the inner pool's forced get(), so that the inner
            // pool's lock still applies
            $refreshed = $this->inner->get($key, $this->wrap($key, $callback, false), \INF);

            return $refreshed instanceof Record ? $refreshed : null;
        } catch (\Throwable $e) {
            $this->logger?->warning('Refreshing "{key}" failed; its stale value stays: {message}', ['key' => $key, 'message' => $e->getMessage(), 'exception' => $e]);

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

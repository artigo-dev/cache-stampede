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
 * Stale-while-revalidate for any Symfony pool: a value past its lifetime is
 * served at once for $staleSeconds more while one process refreshes it.
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
 * A callback wrapper sees only misses, and Symfony's envelope holds a single
 * expiry, so this is a decorator: it stores a Record - the value and its
 * three times - in the inner pool, which keeps it for all of them. The
 * lifetime the callback sets is when the value turns stale.
 *
 * - **Fresh**: served.
 * - **Stale** ($staleSeconds past its lifetime): served at once, with
 *   METADATA_STALE set, and one refresh is scheduled. One process wins the
 *   Election and refreshes; the others serve the stale value and move on.
 *   With a deferrer the refresh runs after the response (see RefreshQueue),
 *   without one it runs inline and the caller gets the new value.
 * - **Kept for errors** ($staleIfErrorSeconds more): computed now, like a
 *   miss; if that throws, the old value is served and the failure logged.
 * - **Gone**: a miss, computed by the inner pool and its callback wrapper.
 *
 * A refresh is the inner pool's forced get() (INF), so it runs through the
 * inner pool's lock wrapper, and on versioned-cache it is guarded like a
 * miss: a delete during it refuses its write. A refresh that throws keeps the
 * stale value. An invalidated value is never served stale: the inner pool
 * answers a miss.
 *
 * The inner pool is this pool's storage: what it holds is Records, so read it
 * through this pool, not directly. Its PSR-6 side is strict - getItem() on a
 * stale value is a miss - and save() wraps the value with this pool's times.
 *
 * With $soft, a value invalidated by tag is served stale too, counted from the
 * invalidation, where the inner pool keeps what a tag invalidation reached
 * (versioned-cache with softGraceSeconds) and the invalidation was not a hard
 * one. The inner pool's callback wrapper - its lock - has to be in place
 * before it is handed over: this pool puts its own in front of it.
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
     * @param int                                   $defaultLifetime the lifetime of a value whose callback sets none; 0 for none, which never turns stale
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

        // CacheItem keeps its state protected, for the pools that build it;
        // closures bound to it are how a decorator does the same
        $this->expiryOf = \Closure::bind(static fn (CacheItem $item): float|int|null => $item->expiry, null, CacheItem::class);
        $this->createItem = \Closure::bind(static function (string $key, CacheItemInterface $innerItem, string $poolHash, bool $taggable, ?float $expiry): CacheItem {
            $item = new CacheItem();
            $item->key = $key;
            $item->innerItem = $innerItem;
            $item->poolHash = $poolHash;
            $item->isTaggable = $taggable;

            $record = $innerItem->isHit() ? $innerItem->get() : null;

            // strict: only a fresh value is a hit here
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
     * Where a refresh goes instead of running inline, such as a RefreshQueue.
     * Null runs it inline.
     *
     * The deferrer is called with the refresh, a \Closure that takes no
     * argument, and a key: a string that is the same for every refresh of
     * one cache key in this pool and differs for any other key or pool, so
     * that it can hold one refresh per key. The key is passed only when the
     * parameter that would receive it takes a string: a second parameter,
     * or a variadic one, that is untyped or typed string, ?string, mixed or
     * a union holding one of them. Any other deferrer, such as one of a
     * single parameter or one whose second parameter is an int, is called
     * with the refresh alone.
     */
    public function setDeferrer(?callable $deferrer): void
    {
        if (null === $deferrer) {
            $this->deferrer = null;

            return;
        }

        $deferrer = $deferrer(...);

        // an internal function refuses an argument it does not declare, and
        // under strict types a typed parameter refuses a string
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
        // the inner pool's own early expiration would act on how long it
        // keeps the Record, not on when the value turns stale
        $record = $this->inner->get($key, $this->wrap($key, $callback, false), \INF === $beta ? \INF : 0.0, $metadata);

        if (!$record instanceof Record) {
            // something else under the key: computed over
            $record = $this->inner->get($key, $this->wrap($key, $callback, false), \INF, $metadata);

            if (!$record instanceof Record) {
                return $record;
            }
        }

        $now = microtime(true);

        if ($record->isFresh($now)) {
            self::describe($metadata, $record, false);

            return $record->value;
        }

        if ($record->isServable($now)) {
            $refreshed = $this->schedule($key, $callback);

            if (null !== $refreshed) {
                self::describe($metadata, $refreshed, false);

                return $refreshed->value;
            }

            self::describe($metadata, $record, true);

            return $record->value;
        }

        // past the time it may be served while refreshed: computed now, and
        // served all the same where it is still kept for errors
        try {
            $computed = $this->inner->get($key, $this->wrap($key, $callback, true), \INF, $metadata);
        } catch (\Throwable $e) {
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
     * Passed on to the inner pool, $hard with it: a pool that tells soft
     * invalidations from hard ones takes it, any other ignores it.
     */
    public function invalidateTags(array $tags, bool $hard = false): bool
    {
        if (!$this->inner instanceof TagAwareCacheInterface) {
            return false;
        }

        $invalidate = [$this->inner, 'invalidateTags'];

        // @phpstan-ignore arguments.count (a pool that tells soft from hard takes the second argument; any other ignores it)
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
     * A queued refresh is still correct after the request: nothing is
     * dropped or run here.
     */
    public function reset(): void
    {
        if ($this->inner instanceof ResetInterface) {
            $this->inner->reset();
        }
    }

    /**
     * The callback the inner pool computes with: the user's, its value
     * stored as a Record. Where $reuseFresh, a value another process stored
     * meanwhile - while this one waited for the inner pool's lock - is taken
     * as it is, rather than computed again.
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
                    // already stored, with its tags: nothing to write
                    $save = false;

                    return $record;
                }
            }

            $value = $callback($item, $save);
            $record = $this->record($value, $item instanceof CacheItem ? ($this->expiryOf)($item) : null);
            $this->keep($item, $record);

            return $record;
        };
    }

    /**
     * Runs the refresh inline and answers what it stored, or hands it to the
     * deferrer and answers null.
     */
    private function schedule(string $key, callable $callback): ?Record
    {
        if (null === $this->deferrer) {
            return $this->refresh($key, $callback);
        }

        // the pool in the key: two pools sharing a deferrer each keep their own
        ($this->deferrer)(function () use ($key, $callback): void {
            $this->refresh($key, $callback);
        }, $key.'@'.$this->poolHash);

        return null;
    }

    /**
     * One refresh, by whoever wins the election: a second look first, since
     * another process may have refreshed it meanwhile, then the inner pool's
     * forced get(). A refresh that fails keeps the stale value; the lock is
     * handed back either way.
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
            // another process refreshes it
            return null;
        }

        try {
            $current = $this->inner->getItem($key);
            $record = $current->isHit() ? $current->get() : null;

            if ($record instanceof Record && $record->isFresh(microtime(true))) {
                return $record;
            }

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
     * A value with this pool's times. $expiry is the lifetime the caller set,
     * as a Unix time; none falls back to the default lifetime, and with none
     * of that either the value never turns stale.
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
     * Puts SoftWrapper in front of the inner pool's callback wrapper - its
     * lock, which a miss still goes through - so that a value invalidated by
     * tag is found where the inner pool kept it.
     */
    private function serveSoftly(CacheItemPoolInterface&CacheInterface $inner): void
    {
        $setCallbackWrapper = [$inner, 'setCallbackWrapper'];

        if (!SoftWrapper::recognises($inner) || !\is_callable($setCallbackWrapper)) {
            throw new InvalidArgumentException(\sprintf('A soft StaleCache needs an inner pool that keeps what a tag invalidation reached (%s) and takes a callback wrapper, "%s" given.', SoftWrapper::AGED_ITEMS, get_debug_type($inner)));
        }

        $previous = $setCallbackWrapper(null);

        if (!\is_callable($previous)) {
            throw new InvalidArgumentException(\sprintf('The callback wrapper of "%s" is not callable.', get_debug_type($inner)));
        }

        $setCallbackWrapper(new SoftWrapper($previous(...)));
    }

    /**
     * Gives the inner item the Record's whole life: the pool keeps it as long
     * as it may be served at all.
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

        // the inner item this pool read, or a fresh read of it
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
     * What get() tells the caller: the value's own lifetime rather than how
     * long the inner pool keeps it, and whether it was served stale.
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
     * Whether the deferrer's parameter for a second argument takes the key.
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

        // an intersection holds objects only
        return false;
    }
}

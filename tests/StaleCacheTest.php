<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\Exception\InvalidArgumentException;
use Artigo\Cache\FleetLock;
use Artigo\Cache\Stale\Record;
use Artigo\Cache\Stale\RefreshQueue;
use Artigo\Cache\StaleCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Serving a value past its lifetime while one process refreshes it: what is
 * served when, what is computed when, and who computes it.
 */
final class StaleCacheTest extends TestCase
{
    private ArrayAdapter $inner;

    private SpyElection $election;

    private int $computed = 0;

    protected function setUp(): void
    {
        $this->inner = new ArrayAdapter(0, false);
        $this->election = new SpyElection();
    }

    public function testAFreshValueIsAHit(): void
    {
        $pool = $this->pool();

        self::assertSame('v', $pool->get('k', $this->compute('v', 60)));
        self::assertSame('v', $pool->get('k', $this->compute('other', 60)), 'served, not computed again');
        self::assertSame(1, $this->computed);

        $item = $pool->getItem('k');
        self::assertTrue($item->isHit());
        self::assertSame('v', $item->get());
    }

    public function testAValuePastItsTtlIsServedStaleAndRefreshedLater(): void
    {
        $this->store('k', 'old', microtime(true) - 1, 60);
        $queue = new RefreshQueue();
        $pool = $this->pool();
        $pool->setDeferrer($queue);

        $value = $pool->get('k', $this->compute('new', 60), metadata: $metadata);

        self::assertSame('old', $value, 'served at once');
        self::assertIsArray($metadata);
        self::assertTrue($metadata[StaleCache::METADATA_STALE] ?? false, 'and said to be stale');
        self::assertSame(0, $this->computed, 'nothing computed before the response');
        self::assertSame(1, $queue->count());

        $queue->flush();

        self::assertSame(1, $this->computed);
        self::assertSame('new', $pool->get('k', $this->compute('newer', 60)));
    }

    public function testWithoutADeferrerTheRefreshRunsInline(): void
    {
        $this->store('k', 'old', microtime(true) - 1, 60);

        $value = $this->pool()->get('k', $this->compute('new', 60), metadata: $metadata);

        self::assertSame('new', $value);
        self::assertIsArray($metadata);
        self::assertArrayNotHasKey(StaleCache::METADATA_STALE, $metadata);
    }

    public function testAReadWithoutStaleTakesAnAgedValueForAMiss(): void
    {
        $this->store('k', 'old', microtime(true) - 1, 60);
        $pool = $this->pool();

        self::assertFalse($pool->getItem('k')->isHit(), 'PSR-6 is strict: a stale value is a miss');
        self::assertFalse($pool->hasItem('k'));
    }

    public function testARefreshFindingTheLockTakenComputesNothing(): void
    {
        $this->store('k', 'old', microtime(true) - 1, 60);
        $this->election->taken = true;

        self::assertSame('old', $this->pool()->get('k', $this->compute('new', 60)));
        self::assertSame(0, $this->computed, 'another process refreshes it');
    }

    public function testARefreshHoldsItsLockForTheConfiguredTtl(): void
    {
        $this->store('k', 'old', microtime(true) - 1, 60);
        $pool = new StaleCache($this->inner, staleSeconds: 60, election: $this->election, refreshLockTtlMs: 1_234);

        $pool->get('k', $this->compute('new', 60));

        self::assertSame([['k', 1_234]], $this->election->asked);
        self::assertSame(1, $this->election->released);
    }

    public function testARefreshThatLandedMeanwhileIsNotRepeated(): void
    {
        $this->store('k', 'old', microtime(true) - 1, 60);
        [$first, $second] = [new RefreshQueue(), new RefreshQueue()];
        $a = $this->pool();
        $a->setDeferrer($first);
        $b = $this->pool();
        $b->setDeferrer($second);

        // two requests, each with a refresh queued
        $a->get('k', $this->compute('new', 60));
        $b->get('k', $this->compute('new', 60));
        $first->flush();
        $second->flush();

        self::assertSame(1, $this->computed, 'the second refresh looked first');
    }

    public function testAnInvalidatedValueIsNeverServedStale(): void
    {
        $inner = new TagAwareAdapter(new ArrayAdapter(0, false));
        $queue = new RefreshQueue();
        $pool = $this->pool($inner);
        $pool->setDeferrer($queue);

        $pool->get('tagged', $this->compute('before', 60, 'articles'));
        $pool->get('deleted', $this->compute('before', 60));
        self::assertTrue($pool->invalidateTags(['articles']));
        self::assertTrue($pool->delete('deleted'));

        self::assertSame('after', $pool->get('tagged', $this->compute('after', 60, 'articles')), 'a miss, computed');
        self::assertSame('after', $pool->get('deleted', $this->compute('after', 60)));
        self::assertSame(0, $queue->count(), 'and nothing served stale');
    }

    public function testAThrowingRefreshKeepsTheStaleValueAndFreesTheLock(): void
    {
        $this->store('k', 'old', microtime(true) - 1, 60);

        $value = $this->pool()->get('k', self::down());

        self::assertSame('old', $value, 'the stale value stays');
        self::assertSame(1, $this->election->released, 'and the lock is handed back');

        $stored = $this->inner->getItem('k')->get();
        self::assertInstanceOf(Record::class, $stored);
        self::assertSame('old', $stored->value, 'still there for the next refresh');
    }

    public function testARefreshWhoseFleetLockCannotBeHandedBackStillServes(): void
    {
        $this->store('k', 'old', microtime(true) - 1, 60);

        // the store takes the lock, then fails on the way back while it
        // still holds it: a second release would fail the same way
        $store = new class extends InMemoryStore {
            public function delete(Key $key): void
            {
                throw new \RuntimeException('the store went away');
            }
        };
        $pool = new StaleCache($this->inner, staleSeconds: 60, election: new FleetLock(new LockFactory($store)));

        self::assertSame('new', $pool->get('k', $this->compute('new', 60)), 'refreshed, and nothing thrown once it is done');
    }

    public function testASoftPoolNeedsAnInnerPoolThatKeepsWhatAnInvalidationReached(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // one that takes a callback wrapper, but drops what it invalidates
        new StaleCache(new TagAwareAdapter($this->inner), staleSeconds: 60, soft: true);
    }

    public function testAStaleValueLivesItsTtlPlusItsStaleTime(): void
    {
        $queue = new RefreshQueue();
        $pool = $this->pool();
        $pool->setDeferrer($queue);

        // a value whose lifetime ended a second ago, written through the pool
        $pool->get('k', static function (ItemInterface $item): string {
            $item->expiresAt(\DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', microtime(true) - 1)) ?: null);

            return 'aged';
        });

        self::assertTrue($this->inner->getItem('k')->isHit(), 'the inner pool keeps it for its stale time');
    }

    public function testAForcedRefreshRecomputesOverAFreshValue(): void
    {
        $pool = $this->pool();
        $pool->get('k', $this->compute('v1', 60));

        self::assertSame('v2', $pool->get('k', $this->compute('v2', 60), \INF));
    }

    public function testAValueAnotherProcessStoredMeanwhileIsNotComputedAgain(): void
    {
        // a pool that calls its callback wrapper, as ArrayAdapter does not
        $inner = new FilesystemAdapter('stale-test-'.bin2hex(random_bytes(4)));
        $put = static function (string $value, float $freshUntil) use ($inner): void {
            $inner->save($inner->getItem('k')->set(new Record($value, $freshUntil, 60, 0))->expiresAfter(3_600));
        };

        // the inner pool's lock: while this process waited for it, the holder
        // stored a fresh value
        $inner->setCallbackWrapper(static function (callable $callback, ItemInterface $item, bool &$save) use ($put): mixed {
            $put('from the holder', microtime(true) + 60);

            return $callback($item, $save);
        });
        $put('old', microtime(true) - 70);

        try {
            self::assertSame('from the holder', $this->pool($inner)->get('k', $this->compute('new', 60)));
            self::assertSame(0, $this->computed);
        } finally {
            $inner->clear();
        }
    }

    public function testThePsr6SideStoresWithThePoolsTimes(): void
    {
        $pool = $this->pool();
        $item = $pool->getItem('k');
        self::assertTrue($pool->save($item->set('v')->expiresAfter(60)));

        $read = $pool->getItem('k');
        self::assertTrue($read->isHit());
        self::assertSame('v', $read->get());

        $stored = $this->inner->getItem('k')->get();
        self::assertInstanceOf(Record::class, $stored);
        self::assertSame(60.0, $stored->staleFor);
        self::assertEqualsWithDelta(microtime(true) + 60, $stored->freshUntil, 1.0);
    }

    /**
     * A computation that fails with an \Error, which no \Exception handler sees.
     */
    private static function down(): \Closure
    {
        return static function (): string {
            throw new \Error('the origin is down');
        };
    }

    private function pool(?CacheItemPoolInterface $inner = null): StaleCache
    {
        $inner ??= $this->inner;
        \assert($inner instanceof CacheInterface);

        return new StaleCache($inner, staleSeconds: 60, election: $this->election);
    }

    /**
     * A callback that counts its calls.
     */
    private function compute(string $value, int $ttl, ?string $tag = null): \Closure
    {
        return function (ItemInterface $item) use ($value, $ttl, $tag): string {
            ++$this->computed;
            $item->expiresAfter($ttl);

            if (null !== $tag) {
                $item->tag($tag);
            }

            return $value;
        };
    }

    /**
     * A Record put straight into the inner pool, kept for as long as it may
     * be served or for $keepFor seconds.
     */
    private function store(string $key, string $value, float $freshUntil, int $stale, int $error = 0, ?int $keepFor = null): void
    {
        $record = new Record($value, $freshUntil, $stale, $error);
        $item = $this->inner->getItem($key)->set($record);
        $item->expiresAfter($keepFor ?? (int) ceil($record->keptUntil() - microtime(true)));
        $this->inner->save($item);
    }
}

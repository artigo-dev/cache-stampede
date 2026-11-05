<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\Stale\RefreshQueue;
use Artigo\Cache\Stale\SoftWrapper;
use Artigo\Cache\StaleCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * A soft pool serves a value a tag invalidation reached, stale, while one
 * process refreshes it - over versioned-cache, which keeps what soft rules
 * reached. Skipped where versioned-cache is not installed.
 */
final class SoftServeTest extends TestCase
{
    /**
     * By name: nothing here depends on versioned-cache being installed.
     */
    private const VERSIONED = 'Artigo\\Cache\\Adapter\\VersionedRedisTagAwareAdapter';

    private \Redis|\Relay\Relay $redis;

    private string $namespace;

    private int $computed = 0;

    protected function setUp(): void
    {
        if (!class_exists(self::VERSIONED) || !interface_exists(SoftWrapper::AGED_ITEMS)) {
            self::markTestSkipped('artigo/versioned-cache with soft invalidation is not installed.');
        }

        $this->redis = Connection::openSingleNode();
        $this->namespace = 'soft-serve'.bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            Connection::sweep($this->redis, $this->namespace.'*');
        }
    }

    public function testASoftValueIsServedAfterAnInvalidationWhileOneRefreshRuns(): void
    {
        $this->pool()->get('menu', $this->compute('before'));
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        // another request, refreshing after its response
        $queue = new RefreshQueue();
        $reader = $this->pool();
        $reader->setDeferrer($queue);
        $value = $reader->get('menu', $this->compute('after'), metadata: $metadata);

        self::assertSame('before', $value, 'served at once');
        self::assertIsArray($metadata);
        self::assertTrue($metadata[StaleCache::METADATA_STALE] ?? false);
        self::assertSame(1, $this->computed, 'nothing computed during the request');
        self::assertSame(1, $queue->count());

        $queue->flush();

        self::assertSame(2, $this->computed, 'one refresh after it');
        self::assertSame('after', $this->pool()->get('menu', $this->compute('later')));
    }

    public function testASoftValueRefreshesInline(): void
    {
        $this->pool()->get('menu', $this->compute('before'));
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        self::assertSame('after', $this->pool()->get('menu', $this->compute('after')), 'without a deferrer the request refreshes it');
    }

    public function testASoftValueKeptForErrorsIsServedAfterAnInvalidation(): void
    {
        $pool = $this->pool(staleSeconds: 0, staleIfErrorSeconds: 600);
        $pool->get('menu', $this->compute('before'));
        self::assertTrue($pool->invalidateTags(['menu']));

        $value = $this->pool(staleSeconds: 0, staleIfErrorSeconds: 600)->get('menu', self::down());

        self::assertSame('before', $value, 'computed at once, and kept for its failure');
    }

    public function testAHardInvalidationIsNeverServedStale(): void
    {
        $this->pool()->get('menu', $this->compute('before'));
        self::assertTrue($this->pool()->invalidateTags(['menu'], hard: true));

        $queue = new RefreshQueue();
        $reader = $this->pool();
        $reader->setDeferrer($queue);

        self::assertSame('after', $reader->get('menu', $this->compute('after')), 'computed now');
        self::assertSame(0, $queue->count(), 'nothing served stale');
    }

    public function testAValueWrittenWithoutSoftIsNeverServedStaleAfterAnInvalidation(): void
    {
        $queue = new RefreshQueue();
        $plain = new StaleCache($this->inner(), staleSeconds: 60, election: new SpyElection());
        $plain->get('menu', $this->compute('before'));
        self::assertTrue($plain->invalidateTags(['menu']));

        $pool = $this->pool();
        $pool->setDeferrer($queue);

        self::assertSame('after', $pool->get('menu', $this->compute('after')), 'the value did not opt in');
        self::assertSame(0, $queue->count());
    }

    public function testAForcedGetAfterASoftInvalidationComputes(): void
    {
        $this->pool()->get('menu', $this->compute('before'));
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        self::assertSame('after', $this->pool()->get('menu', $this->compute('after'), \INF));
    }

    public function testADeleteStaysHard(): void
    {
        $this->pool()->get('menu', $this->compute('before'));
        self::assertTrue($this->pool()->delete('menu'));

        self::assertSame('after', $this->pool()->get('menu', $this->compute('after')));
    }

    private function pool(int $staleSeconds = 60, int $staleIfErrorSeconds = 0): StaleCache
    {
        return new StaleCache($this->inner(), staleSeconds: $staleSeconds, staleIfErrorSeconds: $staleIfErrorSeconds, election: new SpyElection(), soft: true);
    }

    private function inner(): CacheItemPoolInterface&TagAwareCacheInterface
    {
        $versioned = self::VERSIONED;

        /** @var CacheItemPoolInterface&TagAwareCacheInterface $inner */
        $inner = new $versioned($this->redis, $this->namespace, rulesCacheMs: 0, rulesCache: false, softGraceSeconds: 600); // @phpstan-ignore class.notFound (installed or not, see setUp())

        return $inner;
    }

    private static function down(): \Closure
    {
        return static function (): string {
            throw new \RuntimeException('the origin is down');
        };
    }

    private function compute(string $value): \Closure
    {
        return function (ItemInterface $item) use ($value): string {
            ++$this->computed;
            $item->expiresAfter(300);
            $item->tag('menu');

            return $value;
        };
    }
}

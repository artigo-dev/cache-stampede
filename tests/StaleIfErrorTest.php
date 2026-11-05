<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\Stale\Record;
use Artigo\Cache\Stale\RefreshQueue;
use Artigo\Cache\StaleCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Past the time a value may be served while it is refreshed, it is computed
 * like a miss; the time kept for errors is what lets it be served when that
 * computation fails, and nothing else.
 */
final class StaleIfErrorTest extends TestCase
{
    private ArrayAdapter $inner;

    protected function setUp(): void
    {
        $this->inner = new ArrayAdapter(0, false);
    }

    public function testAValuePastItsStaleTimeIsServedWhenTheComputationFails(): void
    {
        $this->store('old', microtime(true) - 70, 60, 600);

        $value = $this->pool(600)->get('k', self::failing(), metadata: $metadata);

        self::assertSame('old', $value);
        self::assertIsArray($metadata);
        self::assertTrue($metadata[StaleCache::METADATA_STALE] ?? false);
    }

    public function testAValuePastItsStaleTimeIsAMissWhenTheComputationSucceeds(): void
    {
        $this->store('old', microtime(true) - 70, 60, 600);

        self::assertSame('new', $this->pool(600)->get('k', static fn (): string => 'new'));
    }

    public function testStaleIfErrorKeepsTheValuePastItsStaleTime(): void
    {
        // written through the pool, so the pool decides how long it is kept
        $pool = $this->pool(600);
        $item = $pool->getItem('k')->set('old');
        $item->expiresAt(\DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', microtime(true) - 70)) ?: null);
        self::assertTrue($pool->save($item));

        self::assertSame('old', $pool->get('k', self::failing()));
    }

    public function testWithoutStaleIfErrorAFailingComputationThrows(): void
    {
        $this->store('old', microtime(true) - 70, 60, 0, keepFor: 3_600);

        $this->expectExceptionMessage('the origin is down');

        $this->pool(0)->get('k', self::failing());
    }

    public function testAValuePastTheTimeKeptForErrorsIsNoFallback(): void
    {
        $this->store('old', microtime(true) - 700, 60, 600, keepFor: 3_600);

        $this->expectExceptionMessage('the origin is down');

        $this->pool(600)->get('k', self::failing());
    }

    public function testAValuePastItsStaleTimeIsAMissWhileItIsStillThere(): void
    {
        // the inner pool still holds it; its own times say it is done
        $this->store('old', microtime(true) - 70, 60, 0, keepFor: 3_600);
        $queue = new RefreshQueue();
        $pool = $this->pool(0);
        $pool->setDeferrer($queue);

        self::assertSame('new', $pool->get('k', static fn (): string => 'new'), 'computed now, not served stale');
        self::assertSame(0, $queue->count());
    }

    private function pool(int $staleIfError): StaleCache
    {
        return new StaleCache($this->inner, staleSeconds: 60, staleIfErrorSeconds: $staleIfError, election: new SpyElection());
    }

    private static function failing(): \Closure
    {
        return static function (): never {
            throw new \RuntimeException('the origin is down');
        };
    }

    private function store(string $value, float $freshUntil, int $stale, int $error, ?int $keepFor = null): void
    {
        $record = new Record($value, $freshUntil, $stale, $error);
        $item = $this->inner->getItem('k')->set($record);
        $item->expiresAfter($keepFor ?? (int) ceil($record->keptUntil() - microtime(true)));
        $this->inner->save($item);
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\FleetLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The contract around a lock that spans the fleet. How many requests it keeps
 * away from the origin needs real processes and belongs to the stampede
 * benchmark; what is asserted here is that the winner computes and hands the
 * lock back, that a loser waits rather than computing beside it, and that a
 * store which cannot be reached degrades to computing instead of to an error.
 */
final class FleetLockTest extends TestCase
{
    private \Redis|\Relay\Relay $redis;

    protected function setUp(): void
    {
        $this->redis = Connection::openSingleNode();
        $this->clean();
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->clean();
        }
    }

    public function testTheWinnerComputesSavesAndReleasesTheLock(): void
    {
        $pool = $this->pool();
        $calls = 0;

        $value = $pool->get('winner', static function (ItemInterface $item) use (&$calls): string {
            ++$calls;
            $item->expiresAfter(60);

            return 'computed';
        });

        self::assertSame('computed', $value);
        self::assertSame(1, $calls);
        self::assertSame('computed', $pool->getItem('winner')->get(), 'the value reached the pool');
        self::assertSame([], $this->redis->keys('cache.stampede.*'), 'the lock was handed back');
    }

    public function testTheLockIsReleasedWhenTheCallbackThrows(): void
    {
        $pool = $this->pool();
        $thrown = null;

        try {
            $pool->get('boom', static function (ItemInterface $item): string {
                throw new \DomainException('origin failed');
            });
        } catch (\DomainException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\DomainException::class, $thrown, 'the exception reaches the caller');
        self::assertSame([], $this->redis->keys('cache.stampede.*'), 'a failed computation must not leave the herd blocked');
    }

    public function testALoserWaitsForTheLockInsteadOfComputingBesideTheWinner(): void
    {
        $pool = $this->pool();

        // someone else holds the lock and is still computing; the item is not
        // in the pool yet, so this call cannot simply read it
        $held = $this->locks()->createLock('cache.stampede.contended', 0.4);
        self::assertTrue($held->acquire(), 'the test needs the lock first');

        $calls = 0;
        $startedAt = microtime(true);

        $value = $pool->get('contended', static function (ItemInterface $item) use (&$calls): string {
            ++$calls;
            $item->expiresAfter(60);

            return 'computed after waiting';
        });

        $waited = (microtime(true) - $startedAt) * 1000;

        self::assertSame('computed after waiting', $value);
        self::assertSame(1, $calls, 'the origin was reached exactly once');
        self::assertGreaterThan(150, $waited, 'the caller waited on the lock rather than racing the holder');
    }

    public function testAForcedRecomputeWaitsForTheLockAndThenTakesIt(): void
    {
        $pool = $this->pool();

        $pool->get('forced', static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return 'from the winner';
        });

        $held = $this->locks()->createLock('cache.stampede.forced', 0.4);
        self::assertTrue($held->acquire(), 'the test needs the lock first');

        $calls = 0;
        $startedAt = microtime(true);

        // INF is Symfony's "recompute now, whatever is cached". It waits for
        // the lock like any other loser and then takes it; going round the lock
        // instead is a forced refresh stampeding.
        $value = $pool->get('forced', static function (ItemInterface $item) use (&$calls): string {
            ++$calls;
            $item->expiresAfter(60);

            return 'recomputed';
        }, \INF);

        $waited = (microtime(true) - $startedAt) * 1000;

        self::assertSame('recomputed', $value, 'force means force: computed, not read back');
        self::assertSame(1, $calls, 'and computed exactly once');
        self::assertGreaterThan(150, $waited, 'behind the lock rather than beside it');
    }

    public function testAnUnreachableStoreComputesInsteadOfFailing(): void
    {
        $unreachable = RedisAdapter::createConnection('redis://127.0.0.1:1', ['lazy' => true]);
        \assert($unreachable instanceof \Redis);

        $pool = new RedisAdapter($this->redis, 'fleetlock-test');
        $pool->setCallbackWrapper(new FleetLock(new LockFactory(new RedisStore($unreachable))));

        $value = $pool->get('down', static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return 'served anyway';
        });

        self::assertSame('served anyway', $value, 'a lock must never be the reason a request fails');
    }

    private function pool(): RedisAdapter
    {
        $pool = new RedisAdapter($this->redis, 'fleetlock-test');
        $pool->setCallbackWrapper(new FleetLock($this->locks(), 5.0));

        return $pool;
    }

    private function locks(): LockFactory
    {
        return new LockFactory(new RedisStore($this->redis));
    }

    private function clean(): void
    {
        Connection::sweep($this->redis, 'fleetlock-test*');
        Connection::sweep($this->redis, 'cache.stampede.*');
    }
}

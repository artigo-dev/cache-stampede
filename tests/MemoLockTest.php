<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\MemoLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The headline property - one origin call across a whole fleet - needs real
 * concurrent processes and belongs to the stampede benchmark. What is asserted
 * here is the contract around it: the winner computes, saves and always gives
 * the lock back; a caller that loses the race waits instead of computing
 * beside the winner; and an unreachable Redis degrades to computing rather
 * than to an error.
 */
final class MemoLockTest extends TestCase
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

    /**
     * Removes what these tests create and nothing else - REDIS_DSN may well
     * point at a database someone is using.
     */
    private function clean(): void
    {
        // the lock keys, and everything the two pools in this class wrote:
        // a leftover item is a hit, and a hit never reaches the callback the
        // assertions are counting
        Connection::sweep($this->redis, 'memolock*');
        Connection::sweep($this->redis, 'unreachable-test*');
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
        self::assertSame([], $this->redis->keys('memolock:lock:*'), 'the lock was released');
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
        self::assertSame([], $this->redis->keys('memolock:lock:*'), 'a failed computation must not leave the herd blocked');
    }

    public function testALoserWaitsForTheLockInsteadOfComputingBesideTheWinner(): void
    {
        $pool = $this->pool(waitTimeoutMs: 50);
        $lockTtlMs = 400;

        // someone else holds the lock and is still computing; the item is not
        // in the pool yet, so this call cannot simply read it
        $this->redis->set('memolock:lock:'.self::tagFor('contended'), 'another-workers-token', ['PX' => $lockTtlMs]);

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
        self::assertGreaterThan($lockTtlMs * 0.5, $waited, 'the caller waited on the lock rather than racing the winner');
    }

    public function testAnUnreachableRedisComputesInsteadOfFailing(): void
    {
        $pool = new RedisAdapter($this->redis, 'unreachable-test');
        $pool->setCallbackWrapper(MemoLock::fromDsn('redis://127.0.0.1:1', lockTtlMs: 1_000, waitTimeoutMs: 50));

        $value = $pool->get('down', static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return 'served anyway';
        });

        self::assertSame('served anyway', $value, 'a cache must never be the reason a request fails');
    }

    private function pool(int $waitTimeoutMs = 5_000): RedisAdapter
    {
        $pool = new RedisAdapter($this->redis, 'memolock-test');
        $pool->setCallbackWrapper(new MemoLock($this->redis, lockTtlMs: 5_000, waitTimeoutMs: $waitTimeoutMs, pollIntervalMs: 10));

        return $pool;
    }

    private static function tagFor(string $key): string
    {
        return '{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
    }
}

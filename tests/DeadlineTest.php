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
use Artigo\Cache\MemoLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

/**
 * A waiter must not sleep into max_execution_time: a second before it, the
 * item is computed unprotected instead, as LockRegistry does. Otherwise a
 * request blocked behind a slow holder is killed with nothing computed and
 * nothing served.
 *
 * The lock is held by "somebody else" - planted straight in Redis - with a
 * TTL far beyond the limit, and the limit is set the way a request sees it:
 * REQUEST_TIME_FLOAT is now, max_execution_time is three seconds, so the
 * deadline is two seconds from here.
 */
final class DeadlineTest extends TestCase
{
    private \Redis|\Relay\Relay $redis;

    protected function setUp(): void
    {
        $this->redis = Connection::openSingleNode();
        Connection::sweep($this->redis, 'deadline*');
        $this->redis->del(self::memoLockKey('slow'));
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        unset($_SERVER['REQUEST_TIME_FLOAT']);

        if (isset($this->redis)) {
            Connection::sweep($this->redis, 'deadline*');
            $this->redis->del(self::memoLockKey('slow'));
        }
    }

    public function testMemoLockStopsWaitingASecondBeforeTheLimit(): void
    {
        $this->redis->set(self::memoLockKey('slow'), 'somebody-else', ['NX', 'PX' => 30_000]);

        $pool = new RedisAdapter($this->redis, 'deadline');
        $pool->setCallbackWrapper(new MemoLock($this->redis, lockTtlMs: 30_000, waitTimeoutMs: 20_000, pollIntervalMs: 50));

        $elapsed = $this->underALimitOf(3, static fn (): string => $pool->get('slow', static fn (): string => 'computed'), $value);

        self::assertSame('computed', $value, 'computed unprotected rather than killed waiting');
        self::assertGreaterThan(1.5, $elapsed, 'it did wait, up to the deadline');
        self::assertLessThan(2.8, $elapsed, 'and not into the limit');
    }

    public function testFleetLockStopsWaitingASecondBeforeTheLimit(): void
    {
        $locks = new LockFactory(new RedisStore($this->redis));
        $held = $locks->createLock('cache.stampede.slow', 30.0);
        self::assertTrue($held->acquire());

        try {
            $pool = new RedisAdapter($this->redis, 'deadline');
            $pool->setCallbackWrapper(new FleetLock($locks, ttl: 30.0));

            $elapsed = $this->underALimitOf(3, static fn (): string => $pool->get('slow', static fn (): string => 'computed'), $value);
        } finally {
            $held->release();
        }

        self::assertSame('computed', $value);
        self::assertGreaterThan(1.5, $elapsed);
        self::assertLessThan(2.8, $elapsed);
    }

    /**
     * Runs $work as a request that started now, with $seconds of
     * max_execution_time, and answers how long it took.
     *
     * @param-out mixed $result
     */
    private function underALimitOf(int $seconds, \Closure $work, mixed &$result): float
    {
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        set_time_limit($seconds);

        $began = microtime(true);

        try {
            $result = $work();
        } finally {
            set_time_limit(0);
        }

        return microtime(true) - $began;
    }

    private static function memoLockKey(string $key): string
    {
        return 'memolock:lock:{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
    }
}

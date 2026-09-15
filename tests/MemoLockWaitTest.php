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
 * How long a loser waits, and on what.
 *
 * The wait is bounded by the lock, not only by the timeout: a lock that is
 * gone is not waited for, a lock about to expire is waited for about that
 * long, and a wake-up that went out before the subscription began costs a
 * slice rather than the whole timeout. The subscriber connection is one per
 * instance, however many slices pass.
 */
final class MemoLockWaitTest extends TestCase
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

    public function testAWaiterComesBackAsSoonAsTheLockExpires(): void
    {
        // a winner that died holding the lock; the wait timeout is far longer
        $this->redis->set($this->lockKeyFor('expiring'), 'dead-workers-token', ['PX' => 300]);

        $pool = $this->pool(waitTimeoutMs: 5_000);
        $calls = 0;
        $startedAt = microtime(true);

        $value = $pool->get('expiring', static function (ItemInterface $item) use (&$calls): string {
            ++$calls;
            $item->expiresAfter(60);

            return 'computed by the survivor';
        });

        $waited = (microtime(true) - $startedAt) * 1000;

        self::assertSame('computed by the survivor', $value);
        self::assertSame(1, $calls);
        self::assertGreaterThan(250, $waited, 'it waited for the lock');
        self::assertLessThan(1_000, $waited, 'and not for the timeout: the lock was looked at, not only listened for');
    }

    public function testAWakeUpPublishedBeforeTheSubscriptionCostsNoWait(): void
    {
        [$lockKey, $channel] = [$this->lockKeyFor('quick'), $this->channelFor('quick')];
        $redis = $this->redis;
        $pool = new RedisAdapter($redis, 'memolock-wait-test');

        // the winner is so quick that it releases and publishes in the window
        // between the loser's failed SET NX and its SUBSCRIBE; the subscriber
        // factory runs in exactly that window, so it plays the winner
        $redis->set($lockKey, 'winners-token', ['PX' => 30_000]);

        $factory = static function () use ($redis, $pool, $lockKey, $channel): \Redis|\Relay\Relay {
            $item = $pool->getItem('quick');
            $item->set('the winner\'s value');
            $item->expiresAfter(60);
            $pool->save($item);

            $redis->del($lockKey);
            $redis->publish($channel, '1');

            return Connection::openSingleNode();
        };

        $pool->setCallbackWrapper(new MemoLock($redis, $factory, lockTtlMs: 30_000, waitTimeoutMs: 5_000));

        $computed = 0;
        $startedAt = microtime(true);

        $value = $pool->get('quick', static function (ItemInterface $item) use (&$computed): string {
            ++$computed;

            return 'the loser\'s value';
        });

        $waited = (microtime(true) - $startedAt) * 1000;

        self::assertSame('the winner\'s value', $value);
        self::assertSame(0, $computed);
        self::assertLessThan(500, $waited, 'a missed message costs at most a slice, never the timeout');
    }

    public function testTheSubscriberConnectionIsOpenedOnce(): void
    {
        $this->redis->set($this->lockKeyFor('held'), 'another-workers-token', ['PX' => 450]);

        $opened = 0;
        $factory = static function () use (&$opened): \Redis|\Relay\Relay {
            ++$opened;

            return Connection::openSingleNode();
        };

        $pool = new RedisAdapter($this->redis, 'memolock-wait-test');
        $pool->setCallbackWrapper(new MemoLock($this->redis, $factory, lockTtlMs: 5_000, waitTimeoutMs: 5_000));

        $pool->get('held', static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return 'v';
        });

        self::assertSame(1, $opened, 'several slices passed on one connection');
    }

    private function pool(int $waitTimeoutMs): RedisAdapter
    {
        $pool = new RedisAdapter($this->redis, 'memolock-wait-test');
        $pool->setCallbackWrapper(new MemoLock($this->redis, static fn (): \Redis|\Relay\Relay => Connection::openSingleNode(), lockTtlMs: 5_000, waitTimeoutMs: $waitTimeoutMs));

        return $pool;
    }

    private function lockKeyFor(string $key): string
    {
        return 'memolock:lock:{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
    }

    private function channelFor(string $key): string
    {
        return 'memolock:wake:{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
    }

    private function clean(): void
    {
        Connection::sweep($this->redis, 'memolock*');
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\Store\PubSubRedisStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\BlockingSharedLockStoreInterface;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

/**
 * Symfony's RedisStore with a wake-up: the component's blocking acquire
 * blocks here instead of polling, bounded by the holder's lock and by the
 * store's timeout, and a release tells the waiters.
 *
 * What one process cannot show is the latency of the wake-up itself - a
 * waiter blocked in SUBSCRIBE cannot also be the releaser. The stampede
 * benchmark's fleetlock-pubsub mode releases twelve processes for that.
 */
final class PubSubRedisStoreTest extends TestCase
{
    private \Redis|\Relay\Relay $redis;

    protected function setUp(): void
    {
        $this->redis = Connection::openSingleNode();
        Connection::sweep($this->redis, 'pubsub-store-*');
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            Connection::sweep($this->redis, 'pubsub-store-*');
        }
    }

    public function testItBlocksForBothKindsOfLock(): void
    {
        $store = PubSubRedisStore::fromDsn(Connection::dsn());

        self::assertInstanceOf(BlockingStoreInterface::class, $store);
        self::assertInstanceOf(BlockingSharedLockStoreInterface::class, $store);
    }

    public function testAFreeLockIsTakenAtOnce(): void
    {
        $lock = (new LockFactory(PubSubRedisStore::fromDsn(Connection::dsn(), waitTimeoutMs: 1_000)))->createLock('pubsub-store-free', 5.0);

        $began = microtime(true);
        self::assertTrue($lock->acquire(true));
        self::assertLessThan(0.2, microtime(true) - $began, 'nothing to wait for');

        $lock->release();
    }

    public function testAHeldLockIsWaitedForNoLongerThanItCanLive(): void
    {
        // somebody else holds it, briefly, and never releases
        $holder = (new LockFactory(new RedisStore($this->redis)))->createLock('pubsub-store-held', 0.4);
        self::assertTrue($holder->acquire());

        $waiter = (new LockFactory(PubSubRedisStore::fromDsn(Connection::dsn(), waitTimeoutMs: 5_000)))->createLock('pubsub-store-held', 5.0);

        $began = microtime(true);
        self::assertTrue($waiter->acquire(true));
        $waited = microtime(true) - $began;

        self::assertGreaterThan(0.3, $waited, 'it waited for the holder\'s lock to run out');
        self::assertLessThan(1.0, $waited, 'and not a slice longer than the lock could live');

        $waiter->release();
    }

    public function testAHolderThatNeverLetsGoIsGivenUpOnAtTheTimeout(): void
    {
        $holder = (new LockFactory(new RedisStore($this->redis)))->createLock('pubsub-store-stuck', 30.0);
        self::assertTrue($holder->acquire());

        try {
            $waiter = (new LockFactory(PubSubRedisStore::fromDsn(Connection::dsn(), waitTimeoutMs: 300)))->createLock('pubsub-store-stuck', 5.0);

            $began = microtime(true);

            try {
                $waiter->acquire(true);
                self::fail('the lock is held for thirty seconds; the waiter must give up');
            } catch (LockConflictedException) {
                // as the component's own blocking acquire reports it
            }

            self::assertLessThan(1.0, microtime(true) - $began, 'within its own timeout, not the holder\'s TTL');
        } finally {
            $holder->release();
        }
    }

    public function testAReleaseWakesTheWaitersOnTheKeysChannel(): void
    {
        $spy = PublishingSpy::fromDsn();
        $store = new PubSubRedisStore($spy, static fn (): \Redis => PublishingSpy::fromDsn(), 5.0);
        $lock = (new LockFactory($store))->createLock('pubsub-store-wake');

        self::assertTrue($lock->acquire());
        $lock->release();

        self::assertSame(['lock:wake:pubsub-store-wake'], $spy->published, 'one wake-up, on the channel named after the key');
    }
}

/**
 * A connection that remembers what it published.
 */
final class PublishingSpy extends \Redis
{
    /** @var list<string> */
    public array $published = [];

    public static function fromDsn(): self
    {
        $url = parse_url(Connection::dsn());
        $host = \is_array($url) && \is_string($url['host'] ?? null) ? $url['host'] : '127.0.0.1';
        $port = \is_array($url) && \is_int($url['port'] ?? null) ? $url['port'] : 6379;
        $redis = new self();

        try {
            $redis->connect($host, $port, 1.0);
        } catch (\Throwable $e) {
            TestCase::markTestSkipped(\sprintf('Redis is not reachable at "%s": %s', Connection::dsn(), $e->getMessage()));
        }

        return $redis;
    }

    public function publish(string $channel, string $message): \Redis|int|false
    {
        $this->published[] = $channel;

        return parent::publish($channel, $message);
    }
}

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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\BlockingSharedLockStoreInterface;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
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
 *
 * A command that fails in a way that can leave a reply arriving late (phpredis
 * before 6.4, played by ShiftingRedis) closes the connection, so that the
 * reply is never read as the answer to the next one. On a cluster that is
 * only a reply cut short: phpredis drops a node whose answer did not come
 * itself.
 */
final class PubSubRedisStoreTest extends TestCase
{
    private \Redis|\Relay\Relay $redis;

    /**
     * Kept until the run ends, so that no later connection gets their
     * object ids.
     *
     * @var list<\Redis|\RedisCluster>
     */
    private static array $kept = []; // @phpstan-ignore property.onlyWritten (held only to keep the objects alive)

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

    /**
     * Each command the store sends, made to fail once. Every one leaves a
     * reply behind that the next command would read.
     *
     * @return iterable<string, array{\Closure(PubSubRedisStore, ShiftingRedis, self): void}>
     */
    public static function failures(): iterable
    {
        // RedisStore sends its scripts with EVALSHA, and with EVAL on
        // Symfony 6.4: either fails
        yield 'save()' => [static function (PubSubRedisStore $store, ShiftingRedis $redis): void {
            $redis->failNext(['evalSha', 'eval']);
            self::swallow(static fn () => $store->save(new Key('pubsub-store-failing')));
        }];

        yield 'saveRead()' => [static function (PubSubRedisStore $store, ShiftingRedis $redis): void {
            $redis->failNext(['evalSha', 'eval']);
            self::swallow(static fn () => $store->saveRead(new Key('pubsub-store-failing')));
        }];

        yield 'putOffExpiration()' => [static function (PubSubRedisStore $store, ShiftingRedis $redis): void {
            $key = new Key('pubsub-store-failing');
            $store->save($key);
            $redis->failNext(['evalSha', 'eval']);
            self::swallow(static fn () => $store->putOffExpiration($key, 10.0));
        }];

        yield 'exists()' => [static function (PubSubRedisStore $store, ShiftingRedis $redis): void {
            $key = new Key('pubsub-store-failing');
            $store->save($key);
            $redis->failNext(['evalSha', 'eval']);
            self::swallow(static fn () => $store->exists($key));
        }];

        yield 'delete()' => [static function (PubSubRedisStore $store, ShiftingRedis $redis): void {
            $key = new Key('pubsub-store-failing');
            $store->save($key);
            $redis->failNext(['evalSha', 'eval']);
            self::swallow(static fn () => $store->delete($key));
        }];

        yield 'waking the waiters' => [static function (PubSubRedisStore $store, ShiftingRedis $redis): void {
            $key = new Key('pubsub-store-failing');
            $store->save($key);
            $redis->failNext(['publish']);
            self::swallow(static fn () => $store->delete($key));
        }];

        yield 'asking how long the holder has' => [static function (PubSubRedisStore $store, ShiftingRedis $redis, self $test): void {
            $test->hold('pubsub-store-failing', 30.0);
            $redis->failNext(['pttl']);
            self::swallow(static fn () => $store->waitAndSave(new Key('pubsub-store-failing')));
        }];
    }

    /**
     * @param \Closure(PubSubRedisStore, ShiftingRedis, self): void $fail
     */
    #[DataProvider('failures')]
    public function testAFailedCommandLeavesNoReplyForTheNextOne(\Closure $fail): void
    {
        if (Connection::isRelay()) {
            self::markTestSkipped('This plays a phpredis connection.');
        }

        $redis = self::$kept[] = ShiftingRedis::fromDsn();
        $store = new PubSubRedisStore($redis, static fn (): \Redis|\Relay\Relay => Connection::openSingleNode(), 5.0, 50);

        $fail($store, $redis, $this);
        self::assertTrue($redis->fired(), 'the failure was played');

        // another process holds this one: refused
        $this->hold('pubsub-store-held', 30.0);

        try {
            $store->save(new Key('pubsub-store-held'));
            self::fail('the lock was taken while another process held it');
        } catch (LockConflictedException) {
        }

        // nobody holds this one: taken
        $free = new Key('pubsub-store-free');
        $store->save($free);
        self::assertTrue($store->exists($free), 'a free lock was taken');
    }

    public function testOnAClusterSubscriberTheWaiterLooksAtTheLockInstead(): void
    {
        $subscriber = new QuietCluster();
        $opened = 0;
        $store = new PubSubRedisStore(Connection::openSingleNode(), static function () use ($subscriber, &$opened): \RedisCluster {
            ++$opened;

            return $subscriber;
        }, 5.0, 5_000);

        foreach (['pubsub-store-polled', 'pubsub-store-polled-again'] as $name) {
            $this->hold($name, 0.3);
            $store->waitAndSave(new Key($name));
        }

        self::assertSame(0, $subscriber->subscribed, 'a cluster SUBSCRIBE would have listened for the whole read timeout of its nodes');
        self::assertSame(1, $opened, 'it was opened once');
        self::assertSame(0, $subscriber->closed, 'and left open: it may be a connection the application still uses');
    }

    /**
     * @return iterable<string, array{\Exception, int}>
     */
    public static function clusterFailures(): iterable
    {
        yield 'an answer that did not come' => [new \RedisClusterException('Error processing response from Redis node!'), 0];
        yield 'a reply cut short' => [new \RedisException('socket error on read socket'), 1];
    }

    #[DataProvider('clusterFailures')]
    public function testOnAClusterOnlyAReplyCutShortClosesTheConnection(\Exception $failure, int $closed): void
    {
        // after the read timeout: phpredis drops a node whose answer did not
        // come itself, and closing the cluster as well would cost its
        // replica reads before phpredis 6.4
        $cluster = self::$kept[] = new ScriptFailingCluster($failure, stall: 0.06);
        $cluster->setOption(\Redis::OPT_READ_TIMEOUT, 0.05);
        $store = new PubSubRedisStore($cluster, static fn (): \RedisCluster => $cluster, 5.0, 50);

        self::swallow(static fn () => $store->save(new Key('pubsub-store-cluster')));

        self::assertSame(1, $cluster->scripts, 'the failure was played');
        self::assertSame($closed, $cluster->closed);
    }

    public function testAReleaseThatGotNoAnswerWakesNobody(): void
    {
        if (Connection::isRelay()) {
            self::markTestSkipped('This plays a phpredis connection.');
        }

        $redis = self::$kept[] = ShiftingRedis::fromDsn();
        $store = new PubSubRedisStore($redis, static fn (): \Redis|\Relay\Relay => Connection::openSingleNode(), 5.0, 50);
        $key = new Key('pubsub-store-unanswered');
        $store->save($key);

        $redis->failNext(['evalSha', 'eval']);
        self::swallow(static fn () => $store->delete($key));

        self::assertSame([], $redis->published, 'the connection broke: a wake-up would be a second attempt to reach Redis');
    }

    public function testAReleaseRedisAnsweredWithAnErrorStillWakesTheWaiters(): void
    {
        if (Connection::isRelay()) {
            self::markTestSkipped('This plays a phpredis connection.');
        }

        $redis = self::$kept[] = ShiftingRedis::fromDsn();
        $store = new PubSubRedisStore($redis, static fn (): \Redis|\Relay\Relay => Connection::openSingleNode(), 5.0, 50);
        $key = new Key('pubsub-store-answered');
        $store->save($key);

        $redis->failNext(['evalSha', 'eval'], answer: "READONLY You can't write against a read only replica.");
        self::swallow(static fn () => $store->delete($key));

        self::assertSame(['lock:wake:pubsub-store-answered'], $redis->published);
    }

    /**
     * Another process's lock on $name, for $ttl seconds; nothing releases it.
     */
    public function hold(string $name, float $ttl): void
    {
        (new RedisStore($this->redis, $ttl))->save(new Key($name));
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function swallow(\Closure $call): void
    {
        try {
            $call();
        } catch (\RedisException|\RedisClusterException|LockConflictedException) {
        }
    }
}

/**
 * A RedisCluster that connects nowhere, whose scripts fail with $failure
 * after $stall seconds, and which counts its close() calls.
 */
final class ScriptFailingCluster extends \RedisCluster
{
    public int $scripts = 0;

    public int $closed = 0;

    public function __construct(
        private readonly \Exception $failure,
        private readonly float $stall = 0.0,
    ) {
    }

    /**
     * @param array<mixed> $args
     */
    public function evalSha(string $script_sha, array $args = [], int $num_keys = 0): mixed
    {
        return $this->fail();
    }

    /**
     * @param array<mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        return $this->fail();
    }

    public function getLastError(): ?string
    {
        return null;
    }

    public function clearLastError(): bool
    {
        return true;
    }

    public function close(): bool
    {
        ++$this->closed;

        return true;
    }

    private function fail(): never
    {
        ++$this->scripts;
        usleep((int) ($this->stall * 1_000_000));

        throw $this->failure;
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

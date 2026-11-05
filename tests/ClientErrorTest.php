<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\Exception\LockUnavailable;
use Artigo\Cache\FleetLock;
use Artigo\Cache\MemoLock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * An \Error from the lock's client or store is a lock problem like any other:
 * the item is computed unprotected rather than the request failed, and
 * exclusively() reports it as LockUnavailable. One test per place the locks
 * talk to their client or store.
 */
final class ClientErrorTest extends TestCase
{
    private \Redis|\Relay\Relay $redis;
    private string $host;
    private int $port;

    protected function setUp(): void
    {
        $this->redis = Connection::openSingleNode();

        if (!class_exists(\Redis::class, false)) {
            self::markTestSkipped('The failing client is an ext-redis one.');
        }

        $host = $this->redis->getHost();
        $port = $this->redis->getPort();

        if (!\is_string($host) || !\is_int($port)) {
            self::markTestSkipped('The connection does not say where it is.');
        }

        // the failing clients connect where the suite does
        $this->host = $host;
        $this->port = $port;
        $this->clean();
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->clean();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lockTakers(): iterable
    {
        yield 'the wrapper' => ['wrapper'];
        yield 'once()' => ['once'];
        yield 'exclusively()' => ['exclusively'];
    }

    #[DataProvider('lockTakers')]
    public function testAnErrorTakingTheLockIsALockProblem(string $taker): void
    {
        $lock = new MemoLock($this->client(['set']), lockTtlMs: 5_000);

        if ('exclusively' === $taker) {
            $this->expectException(LockUnavailable::class);

            $lock->exclusively('taken', static fn (): string => 'run');

            return;
        }

        $value = 'once' === $taker
            ? $lock->once('taken', static fn (): mixed => null, static fn (): string => 'computed')
            : $this->pool($lock)->get('taken', self::compute(...));

        self::assertSame('computed', $value);
    }

    public function testAnErrorAskingWhenTheLockExpiresIsALockProblem(): void
    {
        $this->holdLock('waited', 100);
        $lock = new MemoLock($this->client(['pttl']), fn (): \Redis => $this->client([]), lockTtlMs: 5_000);

        self::assertSame('computed', $this->pool($lock)->get('waited', self::compute(...)));
    }

    public function testAnErrorPollingTheLockIsALockProblem(): void
    {
        $this->holdLock('polled', 100);
        $lock = new MemoLock($this->client(['exists']), lockTtlMs: 5_000, pollIntervalMs: 10);

        self::assertSame('computed', $this->pool($lock)->get('polled', self::compute(...)));
    }

    /**
     * @return iterable<string, array{list<string>|null}>
     */
    public static function brokenSubscribers(): iterable
    {
        yield 'the factory throws' => [null];
        yield 'subscribe() throws' => [['subscribe']];
        yield 'subscribe() and unsubscribe() throw' => [['subscribe', 'unsubscribe']];
        yield 'subscribe(), unsubscribe() and close() throw' => [['subscribe', 'unsubscribe', 'close']];
    }

    /**
     * @param list<string>|null $failing
     */
    #[DataProvider('brokenSubscribers')]
    public function testAnErrorWaitingForTheWakeUpIsALockProblem(?array $failing): void
    {
        $this->holdLock('subscribed', 100);

        if (null === $failing) {
            $factory = static fn (): \Redis => throw new \Error('the subscriber could not be opened');
        } else {
            $factory = fn (): \Redis => $this->client($failing);
        }

        $lock = new MemoLock($this->redis, $factory, lockTtlMs: 5_000);

        self::assertSame('computed', $this->pool($lock)->get('subscribed', self::compute(...)));
    }

    public function testAnErrorFromTheStoreTakingTheLockIsALockProblem(): void
    {
        $lock = new FleetLock(new LockFactory(self::store(save: new \Error('the store failed'))));

        self::assertSame('computed', $this->pool($lock)->get('stored', self::compute(...)));
    }

    public function testAnErrorFromTheStoreWhileWaitingIsALockProblem(): void
    {
        // held elsewhere, so this caller waits on a read lock the store fails to give
        $store = self::store(save: new LockConflictedException('held elsewhere'), saveRead: new \Error('the store failed'));
        $lock = new FleetLock(new LockFactory($store));

        self::assertSame('computed', $this->pool($lock)->get('read', self::compute(...)));
    }

    private static function compute(ItemInterface $item): string
    {
        $item->expiresAfter(60);

        return 'computed';
    }

    private function pool(MemoLock|FleetLock $lock): RedisAdapter
    {
        $pool = new RedisAdapter($this->redis, 'client-error-test');
        $pool->setCallbackWrapper($lock);

        return $pool;
    }

    /**
     * Another worker's lock on $key, for $ms.
     */
    private function holdLock(string $key, int $ms): void
    {
        $tag = '{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
        $this->redis->set('memolock:lock:'.$tag, 'another-workers-token', ['PX' => $ms]);
    }

    /**
     * A connection to the test server whose $failing methods throw an \Error.
     *
     * @param list<string> $failing
     */
    private function client(array $failing): \Redis
    {
        $client = new class($failing) extends \Redis {
            /**
             * @param list<string> $failing
             */
            public function __construct(private readonly array $failing)
            {
                parent::__construct();
            }

            /**
             * @param array<mixed>|null $options
             */
            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->fail(__FUNCTION__);

                return null === $options ? parent::set($key, $value) : parent::set($key, $value, $options);
            }

            public function pttl(string $key): \Redis|int|false
            {
                $this->fail(__FUNCTION__);

                return parent::pttl($key);
            }

            /**
             * @param array<string>|string $key
             * @param string               ...$other_keys
             */
            public function exists(mixed $key, mixed ...$other_keys): \Redis|int|bool
            {
                $this->fail(__FUNCTION__);

                return parent::exists($key, ...$other_keys);
            }

            /**
             * @param array<string> $channels
             */
            public function subscribe(array $channels, callable $cb): bool
            {
                $this->fail(__FUNCTION__);

                return parent::subscribe($channels, $cb);
            }

            /**
             * @param array<string> $channels
             *
             * @return \Redis|array<mixed>|bool
             */
            public function unsubscribe(array $channels): \Redis|array|bool
            {
                $this->fail(__FUNCTION__);

                return parent::unsubscribe($channels);
            }

            public function close(): bool
            {
                $this->fail(__FUNCTION__);

                return parent::close();
            }

            private function fail(string $method): void
            {
                if (\in_array($method, $this->failing, true)) {
                    throw new \Error(\sprintf('%s() failed in the client', $method));
                }
            }
        };

        $client->connect($this->host, $this->port);

        return $client;
    }

    private static function store(?\Throwable $save = null, ?\Throwable $saveRead = null): InMemoryStore
    {
        return new class($save, $saveRead) extends InMemoryStore {
            public function __construct(
                private readonly ?\Throwable $save,
                private readonly ?\Throwable $saveRead,
            ) {
            }

            public function save(Key $key): void
            {
                if (null !== $this->save) {
                    throw $this->save;
                }

                parent::save($key);
            }

            public function saveRead(Key $key): void
            {
                if (null !== $this->saveRead) {
                    throw $this->saveRead;
                }

                parent::saveRead($key);
            }
        };
    }

    private function clean(): void
    {
        Connection::sweep($this->redis, 'memolock*');
        Connection::sweep($this->redis, 'client-error-test*');
    }
}

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
use Artigo\Cache\Stale\Election;
use Artigo\Cache\Stale\FlockElection;
use Artigo\Cache\Stale\Record;
use Artigo\Cache\StaleCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\DeflateMarshaller;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Electing the one process that refreshes: a vote taken without waiting, and
 * handed back. And the Record as it travels through a real marshaller.
 */
final class RefreshElectionTest extends TestCase
{
    private ?\Redis $redis = null;

    protected function tearDown(): void
    {
        if (null !== $this->redis) {
            Connection::sweep($this->redis, 'memolock:refresh:*');
            Connection::sweep($this->redis, 'stale-test*');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function elections(): iterable
    {
        yield 'MemoLock' => ['memo'];
        yield 'FleetLock on a FlockStore' => ['fleet'];
        yield 'FlockElection' => ['flock'];
    }

    #[DataProvider('elections')]
    public function testTryLockIsNonBlockingAndReleasable(string $election): void
    {
        $voter = $this->election($election);

        $release = $voter->tryLock('refreshed', 5_000);
        self::assertNotNull($release, 'nobody holds it: won');

        $began = microtime(true);
        self::assertNull($voter->tryLock('refreshed', 5_000), 'held: lost');
        self::assertLessThan(0.5, microtime(true) - $began, 'and lost at once');

        $release();

        $again = $voter->tryLock('refreshed', 5_000);
        self::assertNotNull($again, 'handed back: won again');
        $again();
    }

    public function testMemoLockHoldsTheRefreshForItsTtl(): void
    {
        $redis = $this->redis();
        $release = (new MemoLock($redis))->tryLock('refreshed', 1_500);
        self::assertNotNull($release);

        $keys = $redis->keys('memolock:refresh:*');
        self::assertIsArray($keys);
        self::assertCount(1, $keys);
        $ttl = $redis->pttl((string) $keys[0]);
        self::assertIsInt($ttl);
        self::assertGreaterThan(1_000, $ttl);
        self::assertLessThanOrEqual(1_500, $ttl);

        $release();
        self::assertSame([], $redis->keys('memolock:refresh:*'));
    }

    public function testTheDecoratorComposesWithDeflateMarshaller(): void
    {
        $inner = new RedisAdapter($this->redis(), 'stale-test', 0, new DeflateMarshaller(new DefaultMarshaller()));
        $pool = new StaleCache($inner, staleSeconds: 60, election: new SpyElection());

        $pool->get('k', static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return str_repeat('a value that compresses well ', 20);
        });

        $stored = (new RedisAdapter($this->redis(), 'stale-test', 0, new DeflateMarshaller(new DefaultMarshaller())))->getItem('k')->get();
        self::assertInstanceOf(Record::class, $stored, 'read back through Deflate');
        self::assertSame(str_repeat('a value that compresses well ', 20), $stored->value);
        self::assertSame(str_repeat('a value that compresses well ', 20), $pool->get('k', static fn (): string => 'computed again'));
    }

    private function election(string $election): Election
    {
        return match ($election) {
            'memo' => new MemoLock($this->redis()),
            'fleet' => new FleetLock(new LockFactory(new FlockStore(sys_get_temp_dir()))),
            default => new FlockElection(sys_get_temp_dir()),
        };
    }

    private function redis(): \Redis
    {
        if (null === $this->redis) {
            $redis = Connection::openSingleNode();

            if (!$redis instanceof \Redis) {
                self::markTestSkipped('These tests read keys through ext-redis.');
            }

            $this->redis = $redis;
        }

        return $this->redis;
    }
}

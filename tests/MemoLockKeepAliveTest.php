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
use Artigo\Cache\KeepAlive;
use Artigo\Cache\MemoLock;
use PHPUnit\Framework\TestCase;

/**
 * Work that may outrun the lock's TTL gets a keep-alive, so a long import does
 * not have its lock expire underneath it while it is still running.
 *
 * The part worth pinning is the failure: extending a lock somebody else has
 * since taken must not appear to work, or the caller carries on believing it
 * has exclusivity it lost some time ago.
 */
final class MemoLockKeepAliveTest extends TestCase
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

    public function testItPushesTheExpiryOut(): void
    {
        // a TTL short enough that the work would outlive it
        $lock = new MemoLock($this->redis, lockTtlMs: 300, waitTimeoutMs: 1_000, pollIntervalMs: 10);
        $key = 'memolock:lock:'.self::tagFor('long-import');
        $remaining = [];

        $lock->exclusively('long-import', function (KeepAlive $keepAlive) use ($key, &$remaining): string {
            usleep(200_000);
            $remaining['before'] = $this->redis->pttl($key);

            $keepAlive();
            $remaining['after'] = $this->redis->pttl($key);

            return 'imported';
        });

        self::assertLessThan(150, $remaining['before'], 'the lock was about to expire');
        self::assertGreaterThan(250, $remaining['after'], 'and the keep-alive pushed it back out');
    }

    public function testItAcceptsATtlOfItsOwn(): void
    {
        $lock = new MemoLock($this->redis, lockTtlMs: 300, waitTimeoutMs: 1_000, pollIntervalMs: 10);
        $key = 'memolock:lock:'.self::tagFor('long-import');
        $remaining = 0;

        $lock->exclusively('long-import', function (KeepAlive $keepAlive) use ($key, &$remaining): string {
            $keepAlive(5_000);
            $remaining = $this->redis->pttl($key);

            return 'imported';
        });

        self::assertGreaterThan(4_000, $remaining, 'the caller may name a longer extension');
    }

    public function testExtendingALockSomebodyElseNowHoldsFails(): void
    {
        $lock = new MemoLock($this->redis, lockTtlMs: 150, waitTimeoutMs: 1_000, pollIntervalMs: 10);
        $key = 'memolock:lock:'.self::tagFor('long-import');
        $thrown = null;

        $lock->exclusively('long-import', function (KeepAlive $keepAlive) use ($key, &$thrown): string {
            // the lock expires and another worker takes it while this one runs
            usleep(200_000);
            $this->redis->set($key, 'another-workers-token', ['PX' => 5_000]);

            try {
                $keepAlive();
            } catch (LockUnavailable $e) {
                $thrown = $e;
            }

            return 'imported';
        });

        self::assertInstanceOf(LockUnavailable::class, $thrown, 'a lock that is no longer ours cannot be extended');
    }

    public function testTheWorkNeedNotWantIt(): void
    {
        $lock = new MemoLock($this->redis, lockTtlMs: 5_000, waitTimeoutMs: 1_000, pollIntervalMs: 10);

        // a closure declaring no parameter is handed one and ignores it
        $value = $lock->exclusively('long-import', static fn (): string => 'imported');

        self::assertSame('imported', $value);
    }

    public function testOnceGetsOneToo(): void
    {
        $lock = new MemoLock($this->redis, lockTtlMs: 300, waitTimeoutMs: 1_000, pollIntervalMs: 10);
        $key = 'memolock:lock:'.self::tagFor('slow-render');
        $produced = null;
        $remaining = 0;

        $value = $lock->once(
            'slow-render',
            exists: static fn (): ?string => $produced,
            make: function (KeepAlive $keepAlive) use ($key, &$produced, &$remaining): string {
                usleep(200_000);
                $keepAlive();
                $remaining = $this->redis->pttl($key);

                return $produced = 'rendered.png';
            },
        );

        self::assertSame('rendered.png', $value);
        self::assertGreaterThan(250, $remaining, 'a slow render can hold its lock open too');
    }

    private static function tagFor(string $key): string
    {
        return '{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
    }

    private function clean(): void
    {
        Connection::sweep($this->redis, 'memolock*');
    }
}

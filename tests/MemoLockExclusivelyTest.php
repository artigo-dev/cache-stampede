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
use Artigo\Cache\MemoLock;
use PHPUnit\Framework\TestCase;

/**
 * A plain critical section: the waiters want the lock, not a result.
 *
 * The one behaviour that sets this apart from the rest of the class is what it
 * does when it cannot have the lock. Everywhere else a failure degrades to
 * doing the work anyway, because a duplicated computation beats a failed
 * request. Here it must not - somebody asking that two workers never run this
 * at the same time would rather hear that it did not run.
 */
final class MemoLockExclusivelyTest extends TestCase
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

    public function testItRunsTheSectionAndHandsTheLockBack(): void
    {
        $lock = $this->lock();
        $ran = 0;

        $value = $lock->exclusively('import:companies', static function () use (&$ran): string {
            ++$ran;

            return 'imported';
        });

        self::assertSame('imported', $value);
        self::assertSame(1, $ran);
        self::assertSame([], $this->redis->keys('memolock:lock:*'), 'the lock was handed back');
    }

    public function testTheLockIsHandedBackWhenTheSectionThrows(): void
    {
        $lock = $this->lock();
        $thrown = null;

        try {
            $lock->exclusively('import:companies', static function (): string {
                throw new \DomainException('the import fell over');
            });
        } catch (\DomainException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\DomainException::class, $thrown, 'the exception reaches the caller');
        self::assertSame([], $this->redis->keys('memolock:lock:*'), 'a failed section must not leave the lock held');
    }

    public function testItWaitsForAHolderRatherThanRunningBesideIt(): void
    {
        $lock = $this->lock(waitTimeoutMs: 100);
        $held = 'memolock:lock:'.self::tagFor('import:companies');

        // somebody else is mid-import; the lock frees itself shortly
        $this->redis->set($held, 'another-workers-token', ['PX' => 400]);

        $ran = 0;
        $startedAt = microtime(true);

        $lock->exclusively('import:companies', static function () use (&$ran): string {
            ++$ran;

            return 'imported';
        });

        $waited = (microtime(true) - $startedAt) * 1000;

        self::assertSame(1, $ran);
        self::assertGreaterThan(200, $waited, 'it waited for the holder instead of running beside it');
    }

    public function testItThrowsRatherThanRunningWhenTheLockIsUnreachable(): void
    {
        $lock = MemoLock::fromDsn('redis://127.0.0.1:1', lockTtlMs: 1_000, waitTimeoutMs: 50);
        $ran = 0;

        try {
            $lock->exclusively('import:companies', static function () use (&$ran): string {
                ++$ran;

                return 'imported';
            });
            self::fail('exclusivity that cannot be given must not be assumed');
        } catch (LockUnavailable) {
            // expected
        }

        self::assertSame(0, $ran, 'the section must not run without the exclusivity it asked for');
    }

    public function testItThrowsWhenTheHolderNeverLetsGo(): void
    {
        $lock = $this->lock(waitTimeoutMs: 20);
        $held = 'memolock:lock:'.self::tagFor('import:companies');

        // held for longer than every attempt put together
        $this->redis->set($held, 'another-workers-token', ['PX' => 10_000]);

        $ran = 0;

        try {
            $lock->exclusively('import:companies', static function () use (&$ran): string {
                ++$ran;

                return 'imported';
            });
            self::fail('a lock that never frees must not be treated as free');
        } catch (LockUnavailable) {
            // expected
        }

        self::assertSame(0, $ran);
    }

    private function lock(int $waitTimeoutMs = 5_000): MemoLock
    {
        return new MemoLock($this->redis, lockTtlMs: 5_000, waitTimeoutMs: $waitTimeoutMs, pollIntervalMs: 10);
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

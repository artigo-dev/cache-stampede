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

/**
 * once() is the half of MemoLock that has nothing to do with a cache pool:
 * produce a thumbnail, a generated file, a provisioned resource - once, while
 * everyone else waits for the thing rather than for the lock.
 *
 * How many callers it keeps away from the work needs real processes and lives
 * in the stampede benchmark. What is asserted here is the contract: the thing
 * is produced once, a caller arriving late never takes a lock at all, the lock
 * is always handed back, and an unreachable Redis produces rather than throws.
 */
final class MemoLockOnceTest extends TestCase
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

    public function testItProducesTheThingAndHandsTheLockBack(): void
    {
        $lock = $this->lock();
        $made = 0;
        $file = null;

        $value = $lock->once(
            'thumbnail:1',
            exists: static fn (): ?string => $file,
            make: static function () use (&$made, &$file): string {
                ++$made;

                return $file = 'thumb-1.png';
            },
        );

        self::assertSame('thumb-1.png', $value);
        self::assertSame(1, $made);
        self::assertSame([], $this->redis->keys('memolock:lock:*'), 'the lock was handed back');
    }

    public function testACallerArrivingAfterTheWorkNeverTakesALock(): void
    {
        $lock = $this->lock();
        $made = 0;

        $value = $lock->once(
            'thumbnail:2',
            exists: static fn (): string => 'already-there.png',
            make: static function () use (&$made): string {
                ++$made;

                return 'made-again.png';
            },
        );

        self::assertSame('already-there.png', $value);
        self::assertSame(0, $made, 'the work must not run when the thing is already there');
        self::assertSame([], $this->redis->keys('memolock:lock:*'), 'and no lock was taken at all');
    }

    public function testAWaiterGetsTheThingRatherThanMakingItAgain(): void
    {
        $lock = $this->lock(waitTimeoutMs: 200);
        $made = 0;

        // somebody else holds the lock and is mid-production; the thing appears
        // while this caller is waiting, which is what the wake-up is for
        $held = 'memolock:lock:'.self::tagFor('thumbnail:3');
        $this->redis->set($held, 'another-workers-token', ['PX' => 300]);

        $produced = null;
        $appearsAt = microtime(true) + 0.15;

        $value = $lock->once(
            'thumbnail:3',
            exists: static function () use (&$produced, $appearsAt): ?string {
                if (null === $produced && microtime(true) >= $appearsAt) {
                    $produced = 'made-by-the-winner.png';
                }

                return $produced;
            },
            make: static function () use (&$made): string {
                ++$made;

                return 'made-by-the-loser.png';
            },
        );

        self::assertSame('made-by-the-winner.png', $value);
        self::assertSame(0, $made, 'the waiter took the winner\'s result instead of repeating the work');
    }

    public function testTheLockIsHandedBackWhenProducingThrows(): void
    {
        $lock = $this->lock();
        $thrown = null;

        try {
            $lock->once(
                'thumbnail:4',
                exists: static fn (): ?string => null,
                make: static function (): string {
                    throw new \DomainException('the renderer fell over');
                },
            );
        } catch (\DomainException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\DomainException::class, $thrown, 'the exception reaches the caller');
        self::assertSame([], $this->redis->keys('memolock:lock:*'), 'a failed production must not leave the herd blocked');
    }

    public function testAnUnreachableRedisProducesInsteadOfFailing(): void
    {
        $lock = MemoLock::fromDsn('redis://127.0.0.1:1', lockTtlMs: 1_000, waitTimeoutMs: 50);
        $made = 0;

        $value = $lock->once(
            'thumbnail:5',
            exists: static fn (): ?string => null,
            make: static function () use (&$made): string {
                ++$made;

                return 'made-anyway.png';
            },
        );

        self::assertSame('made-anyway.png', $value);
        self::assertSame(1, $made, 'a lock must never be the reason the work does not happen');
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

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
 * Per-call overrides: a longer lock for a slow resolver, and no lock at all
 * for a key not worth protecting.
 *
 * Symfony's get() has nowhere to put either - its signature is fixed and $beta
 * governs early expiry rather than locking - so both go through the wrapper
 * being swapped for the length of one call. What matters is that whatever was
 * installed before is there again afterwards, including when the call throws.
 */
final class MemoLockPerCallTest extends TestCase
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

    public function testWithLeavesTheOriginalAlone(): void
    {
        $lock = new MemoLock($this->redis, lockTtlMs: 5_000);
        $slow = $lock->with(lockTtlMs: 30_000);

        self::assertNotSame($lock, $slow, 'with() answers a copy');

        // the original still writes its own TTL
        $pool = new RedisAdapter($this->redis, 'percall-test');
        $pool->setCallbackWrapper($lock);
        $held = null;

        $pool->get('a', function (ItemInterface $item) use (&$held): string {
            $item->expiresAfter(60);
            $held = $this->heldLockTtl();

            return 'value';
        });

        self::assertNotNull($held);
        self::assertLessThan(6_000, $held, 'the lock this one takes still has its own TTL');
    }

    public function testAroundAppliesTheOverrideForOneCallOnly(): void
    {
        $lock = new MemoLock($this->redis, lockTtlMs: 5_000);
        $pool = new RedisAdapter($this->redis, 'percall-test');
        $pool->setCallbackWrapper($lock);

        $held = null;

        $value = $lock->with(lockTtlMs: 30_000)->around($pool, function () use ($pool, &$held): string {
            return $pool->get('slow', function (ItemInterface $item) use (&$held): string {
                $item->expiresAfter(60);
                $held = $this->heldLockTtl();

                return 'slow value';
            });
        });

        self::assertSame('slow value', $value);
        self::assertNotNull($held);
        self::assertGreaterThan(20_000, $held, 'the slow call held a longer lock');

        // and the pool is back on the lock it had
        $after = null;

        $pool->get('back-to-normal', function (ItemInterface $item) use (&$after): string {
            $item->expiresAfter(60);
            $after = $this->heldLockTtl();

            return 'value';
        });

        self::assertNotNull($after);
        self::assertLessThan(6_000, $after, 'the override did not outlive its call');
    }

    public function testTheWrapperIsPutBackWhenTheCallThrows(): void
    {
        $lock = new MemoLock($this->redis, lockTtlMs: 5_000);
        $pool = new RedisAdapter($this->redis, 'percall-test');
        $pool->setCallbackWrapper($lock);

        try {
            $lock->with(lockTtlMs: 30_000)->around($pool, static function (): string {
                throw new \DomainException('the call fell over');
            });
        } catch (\DomainException) {
            // expected
        }

        $held = null;

        $pool->get('after-the-throw', function (ItemInterface $item) use (&$held): string {
            $item->expiresAfter(60);
            $held = $this->heldLockTtl();

            return 'value';
        });

        self::assertNotNull($held);
        self::assertLessThan(6_000, $held, 'a throwing call still hands the pool back its own lock');
    }

    public function testUnguardedTakesNoLockAtAll(): void
    {
        $lock = new MemoLock($this->redis, lockTtlMs: 5_000);
        $pool = new RedisAdapter($this->redis, 'percall-test');
        $pool->setCallbackWrapper($lock);

        $sawALock = null;

        $value = MemoLock::unguarded($pool, function () use ($pool, &$sawALock): string {
            return $pool->get('cheap', function (ItemInterface $item) use (&$sawALock): string {
                $item->expiresAfter(60);
                $sawALock = null !== $this->heldLockTtl();

                return 'cheap value';
            });
        });

        self::assertSame('cheap value', $value);
        self::assertFalse($sawALock, 'a key not worth protecting takes no lock');
    }

    public function testAForcedRecomputeWaitsForTheLockAndThenTakesIt(): void
    {
        $lock = new MemoLock($this->redis, lockTtlMs: 5_000, waitTimeoutMs: 2_000);
        $pool = new RedisAdapter($this->redis, 'percall-test');
        $pool->setCallbackWrapper($lock);

        $pool->get('forced', static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return 'from the winner';
        });

        // somebody else holds the lock and is still computing
        $this->redis->set($this->lockKeyFor('forced'), 'somebody-else', ['NX', 'PX' => 400]);

        $calls = 0;
        $startedAt = microtime(true);

        // INF is Symfony's "recompute now, whatever is cached". It waits for
        // the lock like any other loser and then takes it; going round the lock
        // instead is a forced refresh stampeding.
        $value = $pool->get('forced', static function (ItemInterface $item) use (&$calls): string {
            ++$calls;
            $item->expiresAfter(60);

            return 'recomputed';
        }, \INF);

        $waited = (microtime(true) - $startedAt) * 1000;

        self::assertSame('recomputed', $value, 'force means force: computed, not read back');
        self::assertSame(1, $calls, 'and computed exactly once');
        self::assertGreaterThan(300, $waited, 'behind the lock rather than beside it');
    }

    /**
     * The lock key MemoLock itself would compute, so a test can hold it.
     */
    private function lockKeyFor(string $key): string
    {
        return 'memolock:lock:{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
    }

    /**
     * How long the one held lock has left, or null when none is held.
     *
     * keys() answers an array, or the connection itself in fluent mode, or
     * false; only the first of those is a list of keys.
     */
    private function heldLockTtl(): ?int
    {
        $keys = $this->redis->keys('memolock:lock:*');

        if (!\is_array($keys) || [] === $keys) {
            return null;
        }

        $first = $keys[0];

        if (!\is_string($first)) {
            return null;
        }

        $ttl = $this->redis->pttl($first);

        return \is_int($ttl) ? $ttl : null;
    }

    private function clean(): void
    {
        Connection::sweep($this->redis, 'memolock*');
        Connection::sweep($this->redis, 'percall-test*');
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\KeepAlive;
use Artigo\Cache\MemoLock;
use PHPUnit\Framework\TestCase;

/**
 * The lock speaks Redis 8.4 - `DELEX ... IFEQ`, `SET ... IFEQ` - and has to
 * keep working on servers that do not: Valkey, which spells the release
 * `DELIFEQ`, and everything older, which gets a compare-and-set script. The
 * dialect is learnt from the server's first refusal and remembered, so the
 * price is one refused command per connection, once.
 *
 * The server here is a real Redis 8.x; a connection that refuses the native
 * forms on the client side stands in for the older ones, and the `DELIFEQ`
 * refusal is the real server's own - Redis has no such command.
 */
final class RedisCommandDialectTest extends TestCase
{
    private RefusingRedis $redis;

    protected function setUp(): void
    {
        $this->redis = RefusingRedis::fromDsn();
        $this->clean();
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->clean();
        }
    }

    public function testAServerThatSpeaksRedisEightReleasesNatively(): void
    {
        $lock = new MemoLock($this->redis, lockTtlMs: 5_000, waitTimeoutMs: 500, pollIntervalMs: 10);

        $lock->exclusively('dialect', static fn (): string => 'done');
        $lock->exclusively('dialect', static fn (): string => 'done');

        self::assertSame(0, $this->redis->exists(self::lockKey('dialect')), 'the lock was released');
        self::assertSame(['DELEX IFEQ', 'DELEX IFEQ'], $this->redis->attempts, 'one native command per release, nothing else tried');
    }

    public function testAServerWithoutDelexReleasesThroughTheScriptAndIsAskedOnce(): void
    {
        $this->redis->refuse = ['DELEX'];
        $lock = new MemoLock($this->redis, lockTtlMs: 5_000, waitTimeoutMs: 500, pollIntervalMs: 10);

        $lock->exclusively('dialect', static fn (): string => 'done');

        self::assertSame(0, $this->redis->exists(self::lockKey('dialect')), 'the script released the lock');
        // DELEX refused by the client, DELIFEQ refused by Redis itself, then the script
        self::assertSame(['DELEX IFEQ', 'DELIFEQ'], $this->redis->attempts);

        $lock->exclusively('dialect', static fn (): string => 'done');

        self::assertSame(0, $this->redis->exists(self::lockKey('dialect')));
        self::assertCount(2, $this->redis->attempts, 'the refusals were remembered: the second release went straight to the script');
    }

    public function testAServerWithoutIfeqExtendsThroughTheScriptAndIsAskedOnce(): void
    {
        $this->redis->refuse = ['SET IFEQ'];
        $lock = new MemoLock($this->redis, lockTtlMs: 300, waitTimeoutMs: 500, pollIntervalMs: 10);
        $remaining = [];

        $lock->exclusively('dialect', function (KeepAlive $keepAlive) use (&$remaining): string {
            usleep(200_000);
            $remaining['before'] = $this->redis->pttl(self::lockKey('dialect'));

            $keepAlive();
            $remaining['first'] = $this->redis->pttl(self::lockKey('dialect'));

            $keepAlive();
            $remaining['second'] = $this->redis->pttl(self::lockKey('dialect'));

            return 'done';
        });

        self::assertLessThan(150, $remaining['before'], 'the lock was about to expire');
        self::assertGreaterThan(250, $remaining['first'], 'the script pushed it out');
        self::assertGreaterThan(250, $remaining['second'], 'and again');
        self::assertSame(['SET IFEQ', 'DELEX IFEQ'], $this->redis->attempts, 'IFEQ was tried once, then the script; the release still spoke Redis 8.4');
    }

    public function testAnExtensionOfALostLockStillFailsThroughTheScript(): void
    {
        $this->redis->refuse = ['SET IFEQ'];
        $lock = new MemoLock($this->redis, lockTtlMs: 5_000, waitTimeoutMs: 500, pollIntervalMs: 10);
        $failed = false;

        $lock->exclusively('dialect', function (KeepAlive $keepAlive) use (&$failed): string {
            // somebody else holds it now
            $this->redis->set(self::lockKey('dialect'), 'theirs', ['PX' => 5_000]);

            try {
                $keepAlive();
            } catch (\Artigo\Cache\Exception\LockUnavailable) {
                $failed = true;
            }

            return 'done';
        });

        self::assertTrue($failed, 'the script refuses to extend a lock that carries another token');
        self::assertSame('theirs', $this->redis->get(self::lockKey('dialect')), 'and the release left their lock alone');
    }

    private function clean(): void
    {
        $this->redis->del(self::lockKey('dialect'));
        $this->redis->attempts = [];
        $this->redis->refuse = [];
    }

    private static function lockKey(string $key): string
    {
        return 'memolock:lock:{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
    }
}

/**
 * A connection that can refuse the native conditional forms the way an older
 * server would - a false reply with "unknown command" as the last error - and
 * remembers every raw command it was asked to send.
 */
final class RefusingRedis extends \Redis
{
    /** @var list<string> "DELEX", "DELIFEQ" or "SET IFEQ" */
    public array $refuse = [];

    /** @var list<string> */
    public array $attempts = [];

    private ?string $refusal = null;

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

    public function rawCommand(string $command, mixed ...$args): mixed
    {
        $form = 'SET' === $command && \in_array('IFEQ', $args, true) ? 'SET IFEQ' : $command;
        $this->attempts[] = 'DELEX' === $command ? 'DELEX IFEQ' : $form;

        if (\in_array($form, $this->refuse, true) || \in_array($command, $this->refuse, true)) {
            $this->refusal = \sprintf("ERR unknown command '%s', with args beginning with: ...", $command);

            return false;
        }

        return parent::rawCommand($command, ...$args);
    }

    public function getLastError(): ?string
    {
        return $this->refusal ?? parent::getLastError();
    }

    public function clearLastError(): bool
    {
        $this->refusal = null;

        return parent::clearLastError();
    }
}

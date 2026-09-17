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
 * The server here is whatever REDIS_DSN points at - Redis 8.4 and later,
 * Redis 7.2 or Valkey 9 in CI - and the expectations follow its dialect,
 * probed once. A connection that refuses the native forms on the client side
 * stands in for a server older than the one running; the refusals of commands
 * the real server lacks are its own.
 */
final class RedisCommandDialectTest extends TestCase
{
    private RefusingRedis $redis;

    /** @var array{delex: bool, delifeq: bool}|null what the real server answers to */
    private static ?array $server = null;

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

    public function testAServerReleasesNativelyOnceItsDialectIsKnown(): void
    {
        $lock = new MemoLock($this->redis, lockTtlMs: 5_000, waitTimeoutMs: 500, pollIntervalMs: 10);

        $lock->exclusively('dialect', static fn (): string => 'done');
        $lock->exclusively('dialect', static fn (): string => 'done');

        self::assertSame(0, $this->redis->exists(self::lockKey('dialect')), 'the lock was released');
        self::assertSame([...$this->firstRelease(), ...$this->laterRelease()], $this->redis->attempts, 'the first release found the dialect, the second spoke it and tried nothing else');
    }

    public function testAServerWithoutDelexOrDelifeqReleasesThroughTheScriptAndIsAskedOnce(): void
    {
        // both native forms refused on the client side - on Redis the DELIFEQ
        // refusal would be the server's own, on Valkey it would not
        $this->redis->refuse = ['DELEX', 'DELIFEQ'];
        $lock = new MemoLock($this->redis, lockTtlMs: 5_000, waitTimeoutMs: 500, pollIntervalMs: 10);

        $lock->exclusively('dialect', static fn (): string => 'done');

        self::assertSame(0, $this->redis->exists(self::lockKey('dialect')), 'the script released the lock');
        self::assertSame(['DELEX IFEQ', 'DELIFEQ'], $this->redis->attempts, 'both native forms were tried, then the script');

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
        self::assertSame(['SET IFEQ', ...$this->firstRelease()], $this->redis->attempts, 'IFEQ was tried once, then the script; the release spoke whatever the server does');
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

    /**
     * What a release records on this server the first time: `DELEX` alone
     * where the server has it, otherwise `DELEX` refused and `DELIFEQ` tried,
     * whether that one is answered (Valkey) or refused too (older Redis, which
     * then gets the script; the script is not a raw command and leaves no
     * trace here).
     *
     * @return list<string>
     */
    private function firstRelease(): array
    {
        return $this->server()['delex'] ? ['DELEX IFEQ'] : ['DELEX IFEQ', 'DELIFEQ'];
    }

    /**
     * And every release after, the refusals remembered: the one native form
     * the server has, or nothing at all on the way to the script.
     *
     * @return list<string>
     */
    private function laterRelease(): array
    {
        $server = $this->server();

        return $server['delex'] ? ['DELEX IFEQ'] : ($server['delifeq'] ? ['DELIFEQ'] : []);
    }

    /**
     * @return array{delex: bool, delifeq: bool}
     */
    private function server(): array
    {
        return self::$server ??= [
            'delex' => $this->redis->knows('DELEX', self::lockKey('probe'), 'IFEQ', 'x'),
            'delifeq' => $this->redis->knows('DELIFEQ', self::lockKey('probe'), 'x'),
        ];
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

    /**
     * Whether the real server answers this command at all - asked directly,
     * so it leaves no trace in $attempts and cannot be refused from here.
     */
    public function knows(string $command, mixed ...$args): bool
    {
        $known = false !== parent::rawCommand($command, ...$args);
        parent::clearLastError();

        return $known;
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

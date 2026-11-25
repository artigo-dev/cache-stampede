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
use Artigo\Cache\RedisCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * What a phpredis RedisCluster does differently, played without a cluster.
 *
 * Its node connections keep the read timeout they were opened with, so a
 * SUBSCRIBE cannot listen for a slice: the waiter polls instead, the
 * subscriber is let go but not closed, and a SUBSCRIBE that comes back
 * without a message is no wake-up. It does not follow a redirect for a raw
 * command, so a release during a slot move goes through the script, which
 * it follows; but not after a read timeout, not for a form sent through
 * phpredis's own method, and not for a while after either of those failed:
 * then a node is down, and only the raw form, which fails at once, goes out.
 * It drops a node's connection itself when one of its own commands fails,
 * so the lock closes a cluster connection only when a late answer may sit
 * on it. And a closed cluster connection before phpredis 6.4 fails every
 * read from a replica, which is said once.
 */
final class ClusterConnectionTest extends TestCase
{
    private \Redis|\Relay\Relay $redis;

    /**
     * Every connection double a test made, kept until the run ends: the lock
     * remembers what a connection refused by its object id, and a freed id
     * goes to the next object.
     *
     * @var list<object>
     */
    private static array $kept = []; // @phpstan-ignore property.onlyWritten (held only to keep the objects alive)

    protected function setUp(): void
    {
        $this->redis = Connection::openSingleNode();

        if (Connection::isRelay()) {
            self::markTestSkipped('These tests play phpredis connections.');
        }

        Connection::sweep($this->redis, 'memolock*');
        Connection::sweep($this->redis, 'cluster-connection-test*');
        self::pauseDetoursUntil(0.0);
    }

    protected function tearDown(): void
    {
        self::pauseDetoursUntil(0.0);

        if (isset($this->redis)) {
            Connection::sweep($this->redis, 'memolock*');
            Connection::sweep($this->redis, 'cluster-connection-test*');
        }
    }

    public function testASubscriberOnAClusterIsNotAskedToListen(): void
    {
        $subscriber = new QuietCluster();
        $opened = 0;
        $pool = $this->pool(new MemoLock($this->redis, static function () use ($subscriber, &$opened): \RedisCluster {
            ++$opened;

            return $subscriber;
        }, lockTtlMs: 5_000, waitTimeoutMs: 5_000));

        foreach (['polled', 'polled again'] as $key) {
            $this->holdLock($key, 300);
            self::assertSame('computed', $pool->get($key, self::compute(...)), 'the waiter polled the lock, saw it go, and took it');
        }

        self::assertSame(0, $subscriber->subscribed, 'a cluster SUBSCRIBE would have listened for the whole read timeout of its nodes');
        self::assertSame(1, $opened, 'it was opened once');
        self::assertSame(0, $subscriber->closed, 'and left open: it may be a connection the application still uses');
    }

    public function testASubscribeThatComesBackWithoutAMessageIsNoWakeUp(): void
    {
        // comes back after each slice without a message and without an
        // exception, as a cluster SUBSCRIBE does when its read times out
        $subscriber = new class extends \Redis {
            public function setOption(int $option, mixed $value): bool
            {
                return true;
            }

            /**
             * @param array<string> $channels
             */
            public function subscribe(array $channels, callable $cb): bool
            {
                usleep(20_000);

                return false;
            }

            /**
             * @param array<string> $channels
             *
             * @return \Redis|array<mixed>|bool
             */
            public function unsubscribe(array $channels): \Redis|array|bool // @phpstan-ignore return.unusedType, return.unusedType (the parent's type)
            {
                return [];
            }
        };
        $redis = self::$kept[] = ShiftingRedis::fromDsn();
        $this->holdLock('quiet', 400);

        $value = $this->pool(new MemoLock($redis, static fn (): \Redis => $subscriber, lockTtlMs: 5_000, waitTimeoutMs: 5_000))
            ->get('quiet', self::compute(...));

        self::assertSame('computed', $value);
        self::assertSame(2, $redis->count('set'), 'one SET NX found the lock held, the next one took it once it was gone');
    }

    public function testARedirectedReleaseGoesThroughTheScript(): void
    {
        $cluster = self::$kept[] = new RedirectingCluster();

        self::assertTrue(RedisCommand::deleteIfEqual($cluster, 'cluster-connection-test:lock', 'token'));
        self::assertSame(1, $cluster->evals, 'EVAL follows the redirect that the raw command did not');
        self::assertTrue(RedisCommand::expireIfEqual($cluster, 'cluster-connection-test:lock', 'token', 1_000));
        self::assertSame(2, $cluster->evals);

        RedisCommand::deleteIfEqual($cluster, 'cluster-connection-test:lock', 'token');
        self::assertSame(['DELEX', 'SET', 'DELEX'], $cluster->raws, 'a redirect is not a refusal: the native form is tried again next time');
        self::assertSame(0, $cluster->closed, 'a redirect was read in full, so the connection stays open');
    }

    public function testOnAClusterOnlyAConnectionThatMayHoldALateAnswerIsClosed(): void
    {
        $cluster = self::$kept[] = new RedirectingCluster();
        $cluster->setOption(\Redis::OPT_READ_TIMEOUT, 0.05);
        $fail = static function (\Exception $e, bool $raw, float $after = 0.0) use ($cluster): void {
            try {
                RedisCommand::call($cluster, static function () use ($e, $after): never {
                    usleep((int) ($after * 1_000_000));

                    throw $e;
                }, null, $raw);
            } catch (\Exception) {
            }
        };

        $fail(new \RedisClusterException('Timed out attempting to find data in the correct node!'), false, 0.06);
        self::assertSame(0, $cluster->closed, 'phpredis dropped the node of a command of its own itself');

        $fail(new \RedisClusterException('Unable to send command to the specified node'), true);
        self::assertSame(0, $cluster->closed, 'a raw command that failed at once was redirected or refused, and read in full');

        $fail(new \RedisClusterException('Unable to send command to the specified node'), true, 0.06);
        self::assertSame(1, $cluster->closed, 'a raw command that waited out the read timeout may have an answer coming');

        $fail(new \RedisException('socket error on read socket'), false);
        self::assertSame(2, $cluster->closed, 'and so may a reply cut short');
    }

    public function testAnExtensionGoesThroughSetWhichFollowsTheRedirect(): void
    {
        if (version_compare((string) phpversion('redis'), '6.2.0', '<')) {
            self::markTestSkipped('phpredis takes IFEQ in SET from 6.2.');
        }

        $cluster = self::$kept[] = new TypedCluster();

        self::assertTrue(RedisCommand::expireIfEqual($cluster, 'cluster-connection-test:lock', 'token', 1_000));
        self::assertSame([['PX' => 1_000, 'IFEQ' => 'token']], $cluster->sets);
        self::assertSame([], $cluster->raws);
        self::assertSame(0, $cluster->evals);
    }

    public function testAReleaseGoesThroughDelexWhichFollowsTheRedirect(): void
    {
        if (!(new \ReflectionClass(\RedisCluster::class))->hasMethod('delex')) {
            self::markTestSkipped('This phpredis has no delex().');
        }

        $cluster = self::$kept[] = new TypedCluster();

        self::assertTrue(RedisCommand::deleteIfEqual($cluster, 'cluster-connection-test:lock', 'token'));
        self::assertSame(['DELEX'], $cluster->typed);
        self::assertSame([], $cluster->raws);
        self::assertSame(0, $cluster->evals);
    }

    public function testOnValkeyAReleaseGoesThroughDelifeqWhichFollowsTheRedirect(): void
    {
        if (!(new \ReflectionClass(\RedisCluster::class))->hasMethod('delifeq')) {
            self::markTestSkipped('This phpredis has no delifeq().');
        }

        // a server without DELEX: refused, as Valkey refuses it
        $cluster = self::$kept[] = new TypedCluster(refuseDelex: true);

        self::assertTrue(RedisCommand::deleteIfEqual($cluster, 'cluster-connection-test:lock', 'token'));
        self::assertContains('DELIFEQ', $cluster->typed);
        self::assertNotContains('DELIFEQ', $cluster->raws);
        self::assertSame(0, $cluster->evals);
    }

    public function testARawCommandThatTimedOutIsNotSentAgainAsAScript(): void
    {
        $cluster = self::$kept[] = new RedirectingCluster(stall: 0.06);
        $cluster->setOption(\Redis::OPT_READ_TIMEOUT, 0.05);

        try {
            RedisCommand::deleteIfEqual($cluster, 'cluster-connection-test:lock', 'token');
            self::fail('the release did not get an answer in time');
        } catch (\RedisClusterException) {
        }

        self::assertSame(0, $cluster->evals, 'that node did not answer, and the script would wait as long');
    }

    public function testAScriptThatFailedAfterARedirectIsNotSentAgainForAWhile(): void
    {
        // the raw command fails at once, as for a redirect; then the script
        // fails too: the node is down
        $cluster = self::$kept[] = new RedirectingCluster(down: true);
        $release = static function () use ($cluster): void {
            try {
                RedisCommand::deleteIfEqual($cluster, 'cluster-connection-test:lock', 'token');
                self::fail('the node is down');
            } catch (\RedisClusterException) {
            }
        };

        $release();
        self::assertSame(1, $cluster->evals, 'the script was tried once');

        $release();
        self::assertSame(['DELEX', 'DELEX'], $cluster->raws);
        self::assertSame(1, $cluster->evals, 'and not again while the pause lasts');

        // the pause runs out
        self::pauseDetoursUntil(microtime(true) - 0.001);
        $release();
        self::assertSame(2, $cluster->evals, 'then it is tried again');
    }

    public function testAFormSentThroughItsOwnMethodThatFailedIsSentRawForAWhile(): void
    {
        if (version_compare((string) phpversion('redis'), '6.2.0', '<')) {
            self::markTestSkipped('phpredis takes IFEQ in SET from 6.2.');
        }

        // SET follows a redirect itself: what it throws is a node that is down
        $cluster = self::$kept[] = new TypedCluster(down: true);
        $extend = static function () use ($cluster): void {
            try {
                RedisCommand::expireIfEqual($cluster, 'cluster-connection-test:lock', 'token', 1_000);
                self::fail('the node is down');
            } catch (\RedisClusterException) {
            }
        };

        $extend();
        self::assertSame(['SET'], $cluster->typed);
        self::assertSame(0, $cluster->evals, 'a script would only look for the same node again');

        // on a node that is down phpredis's own method takes up to the
        // cluster's timeout, a raw command fails at once
        $extend();
        self::assertSame(['SET'], $cluster->typed, 'not again while the pause lasts');
        self::assertSame(['SET'], $cluster->raws, 'the raw form instead');
        self::assertSame(0, $cluster->evals, 'and no script after it');

        // the pause runs out
        self::pauseDetoursUntil(microtime(true) - 0.001);
        $extend();
        self::assertSame(['SET', 'SET'], $cluster->typed, 'then its own method again');
    }

    public function testAClosedClusterConnectionThatReadsFromReplicasIsReportedOnce(): void
    {
        $cluster = self::$kept[] = new RedirectingCluster();
        $cluster->setOption(\RedisCluster::OPT_SLAVE_FAILOVER, \RedisCluster::FAILOVER_DISTRIBUTE);
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            /**
             * @param string|\Stringable   $message
             * @param array<string, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                if ('warning' === $level) {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        // two replies cut short: both close the connection
        for ($i = 0; $i < 2; ++$i) {
            try {
                RedisCommand::call($cluster, static fn (): mixed => throw new \RedisException('socket error on read socket'), $logger);
            } catch (\RedisException) {
            }
        }

        $expected = version_compare((string) phpversion('redis'), '6.4.0-dev', '<') ? 1 : 0;
        self::assertCount($expected, $logger->warnings, 'replica reads after close() fail before phpredis 6.4, and it is said once');
    }

    /**
     * After a node that was down, the process sends the conditional forms to
     * a cluster only raw for a while: each test starts without that pause.
     */
    private static function pauseDetoursUntil(float $until): void
    {
        (new \ReflectionProperty(RedisCommand::class, 'detoursPausedUntil'))->setValue(null, $until);
    }

    private function pool(MemoLock $lock): RedisAdapter
    {
        $pool = new RedisAdapter($this->redis, 'cluster-connection-test');
        $pool->setCallbackWrapper($lock);

        return $pool;
    }

    private function holdLock(string $key, int $ms): void
    {
        $tag = '{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
        $this->redis->set('memolock:lock:'.$tag, 'theirs', ['PX' => $ms]);
    }

    private static function compute(ItemInterface $item): string
    {
        $item->expiresAfter(60);

        return 'computed';
    }
}

/**
 * A RedisCluster that connects nowhere and counts the SUBSCRIBEs it was
 * asked for. Each comes back without a message.
 */
final class QuietCluster extends \RedisCluster
{
    public int $subscribed = 0;

    public int $closed = 0;

    public function __construct()
    {
    }

    /**
     * @param array<string> $channels
     */
    public function subscribe(array $channels, callable $cb): void
    {
        ++$this->subscribed;
    }

    /**
     * @param array<string> $channels
     *
     * @return array<mixed>|bool
     */
    public function unsubscribe(array $channels): array|bool // @phpstan-ignore return.unusedType (the parent's type)
    {
        return [];
    }

    public function close(): bool
    {
        ++$this->closed;

        return true;
    }
}

/**
 * A RedisCluster that connects nowhere, whose raw commands are refused with
 * the exception phpredis throws for a redirect it does not follow, after
 * $stall seconds, and whose EVAL answers 1, or with $down fails at once as
 * for a node that cannot be reached.
 */
final class RedirectingCluster extends \RedisCluster
{
    /** @var list<string> */
    public array $raws = [];

    public int $evals = 0;

    public int $closed = 0;

    public function __construct(
        private readonly float $stall = 0.0,
        private readonly bool $down = false,
    ) {
    }

    public function close(): bool
    {
        ++$this->closed;

        return true;
    }

    /**
     * @param array<mixed>|string $key_or_address
     */
    public function rawCommand(array|string $key_or_address, string $command, mixed ...$args): mixed
    {
        $this->raws[] = $command;
        usleep((int) ($this->stall * 1_000_000));

        throw new \RedisClusterException('Unable to send command to the specified node');
    }

    /**
     * @param array<mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        ++$this->evals;

        if ($this->down) {
            throw new \RedisClusterException("Can't communicate with any node in the cluster");
        }

        return 1;
    }
}

/**
 * A RedisCluster that connects nowhere and answers the lock's commands
 * through phpredis's own methods, which follow a redirect. Its raw commands
 * are refused as a redirect it does not follow, with $refuseDelex the
 * server does not know DELEX, and with $down its SET fails at once as for a
 * node that cannot be reached.
 */
final class TypedCluster extends \RedisCluster
{
    /** @var list<string> */
    public array $typed = [];

    /** @var list<string> */
    public array $raws = [];

    /** @var list<mixed> */
    public array $sets = [];

    public int $evals = 0;

    private ?string $error = null;

    public function __construct(
        private readonly bool $refuseDelex = false,
        private readonly bool $down = false,
    ) {
    }

    /**
     * @param array<mixed>|null $options
     */
    public function delex(string $key, ?array $options = null): \RedisCluster|int|false // @phpstan-ignore return.unusedType (the parent's type)
    {
        $this->typed[] = 'DELEX';

        if ($this->refuseDelex) {
            $this->error = "ERR unknown command 'DELEX', with args beginning with: ...";

            return false;
        }

        return 1;
    }

    public function delifeq(string $key, mixed $value): \RedisCluster|int|false // @phpstan-ignore return.unusedType, return.unusedType (the parent's type)
    {
        $this->typed[] = 'DELIFEQ';

        return 1;
    }

    /**
     * @param array<mixed>|null $options
     */
    public function set(string $key, mixed $value, mixed $options = null): \RedisCluster|string|bool
    {
        $this->typed[] = 'SET';
        $this->sets[] = $options;

        if ($this->down) {
            throw new \RedisClusterException("Can't communicate with any node in the cluster");
        }

        return true;
    }

    /**
     * @param array<mixed>|string $key_or_address
     */
    public function rawCommand(array|string $key_or_address, string $command, mixed ...$args): mixed
    {
        $this->raws[] = $command;

        if ('DELEX' === $command && $this->refuseDelex) {
            $this->error = "ERR unknown command 'DELEX', with args beginning with: ...";

            return false;
        }

        throw new \RedisClusterException('Unable to send command to the specified node');
    }

    /**
     * @param array<mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        ++$this->evals;

        return 1;
    }

    public function getLastError(): ?string
    {
        return $this->error;
    }

    public function clearLastError(): bool
    {
        $this->error = null;

        return true;
    }
}

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
use Artigo\Cache\RedisCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * phpredis before 6.4 can keep a connection open after a read timed out, and
 * then reads the late reply as the answer to the next command, and every
 * reply after that one late. On the lock, a SET NX that set the key then
 * reads as refused, and one that was refused as having set it, while
 * another process holds the lock. A command that fails closes the
 * connection, wherever the lock talks to Redis; these tests play the late
 * reply with ShiftingRedis.
 *
 * A SET NX whose answer could not be read may still be run by Redis: the
 * lock is given back, without waiting long for a server that just did not
 * answer, and for a while not at all, in the whole process, once that
 * failed. A SET NX that was never sent is not given back.
 *
 * And a waiter waits for a holder at work as long as it works, however
 * often its wait times out; only rounds in which Redis cannot say whether
 * the lock is there are counted.
 */
final class LateReplyTest extends TestCase
{
    private \Redis|\Relay\Relay $redis;

    /**
     * Every ShiftingRedis a test opened, kept until the run ends: the lock
     * remembers what a connection refused by its object id, and a freed id
     * goes to the next object.
     *
     * @var list<ShiftingRedis>
     */
    private static array $kept = []; // @phpstan-ignore property.onlyWritten (held only to keep the objects alive)

    protected function setUp(): void
    {
        $this->redis = Connection::openSingleNode();

        if (Connection::isRelay()) {
            self::markTestSkipped('These tests play a phpredis connection.');
        }

        self::resumeGiveBacks();
        $this->clean();
    }

    protected function tearDown(): void
    {
        self::resumeGiveBacks();

        if (isset($this->redis)) {
            $this->clean();
        }
    }

    /**
     * Each place the lock talks to Redis, made to fail once. Every one leaves
     * a reply behind that the next command would read.
     *
     * @return iterable<string, array{\Closure(MemoLock, ShiftingRedis, self): void, bool}>
     */
    public static function failures(): iterable
    {
        yield 'taking the lock' => [static function (MemoLock $lock, ShiftingRedis $redis): void {
            $redis->failNext(['set']);
            self::expectUnavailable(static fn () => $lock->exclusively('failing', static fn (): string => 'ran'));
        }, false];

        yield 'releasing it' => [static function (MemoLock $lock, ShiftingRedis $redis): void {
            $lock->exclusively('failing', static function () use ($redis): string {
                $redis->failNext(['rawCommand', 'eval']);

                return 'ran';
            });
        }, false];

        yield 'releasing it through the script' => [static function (MemoLock $lock, ShiftingRedis $redis): void {
            $redis->refuse = ['DELEX', 'DELIFEQ'];
            $lock->exclusively('failing', static function () use ($redis): string {
                $redis->failNext(['eval']);

                return 'ran';
            });
            $redis->refuse = [];
        }, false];

        yield 'waking the waiters' => [static function (MemoLock $lock, ShiftingRedis $redis): void {
            $lock->exclusively('failing', static function () use ($redis): string {
                $redis->failNext(['publish']);

                return 'ran';
            });
        }, false];

        yield 'extending it' => [static function (MemoLock $lock, ShiftingRedis $redis): void {
            $lock->exclusively('failing', static function (KeepAlive $keepAlive) use ($redis): string {
                $redis->failNext(['set', 'rawCommand', 'eval']);

                try {
                    $keepAlive();
                } catch (LockUnavailable) {
                }

                return 'ran';
            });
        }, false];

        yield 'polling it' => [static function (MemoLock $lock, ShiftingRedis $redis, self $test): void {
            $test->holdLock('failing', 30_000);
            $redis->failNext(['exists']);
            self::expectUnavailable(static fn () => $lock->exclusively('failing', static fn (): string => 'ran'));
        }, false];

        yield 'asking how long it lives' => [static function (MemoLock $lock, ShiftingRedis $redis, self $test): void {
            $test->holdLock('failing', 30_000);
            $redis->failNext(['pttl']);
            self::expectUnavailable(static fn () => $lock->exclusively('failing', static fn (): string => 'ran'));
        }, true];

        yield 'electing a refresher' => [static function (MemoLock $lock, ShiftingRedis $redis): void {
            $redis->failNext(['set']);

            try {
                $lock->tryLock('failing', 5_000);
            } catch (\RedisException) {
            }
        }, false];

        yield 'handing the election back' => [static function (MemoLock $lock, ShiftingRedis $redis): void {
            $release = $lock->tryLock('failing', 5_000);
            self::assertNotNull($release);
            $redis->failNext(['rawCommand', 'eval']);

            try {
                $release();
            } catch (\RedisException) {
            }
        }, false];
    }

    /**
     * @param \Closure(MemoLock, ShiftingRedis, self): void $fail
     */
    #[DataProvider('failures')]
    public function testAFailedCommandLeavesNoReplyForTheNextOne(\Closure $fail, bool $subscribes): void
    {
        $redis = $this->shifting();
        $factory = $subscribes ? static fn (): \Redis|\Relay\Relay => Connection::openSingleNode() : null;
        $lock = new MemoLock($redis, $factory, lockTtlMs: 5_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        $fail($lock, $redis, $this);
        self::assertTrue($redis->fired(), 'the failure was played');

        // another process holds this one: the section must not run
        $this->holdLock('held', 30_000);
        $ran = false;

        try {
            $lock->exclusively('held', static function () use (&$ran): void {
                $ran = true;
            });
        } catch (LockUnavailable) {
        }

        self::assertFalse($ran, 'the section ran while another process held the lock');
        self::assertSame('theirs', $this->redis->get(self::lockKey('held')));

        // nobody holds this one: the section runs
        self::assertSame('ran', $lock->exclusively('free', static fn (): string => 'ran'), 'a free lock was taken');
        self::assertSame(0, $this->redis->exists(self::lockKey('free')), 'and handed back');
    }

    public function testATimedOutReleaseNeverRunsASectionBesideItsHolder(): void
    {
        $redis = $this->shifting();
        $lock = new MemoLock($redis, lockTtlMs: 5_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        // the release of the first lock times out, and its answer comes late
        $lock->exclusively('first', static function () use ($redis): void {
            $redis->failNext(['rawCommand', 'eval']);
        });

        // a refresh lock nobody holds, then a lock another process holds
        $lock->tryLock('free', 30_000);
        $this->holdLock('second', 30_000);
        $ran = false;

        try {
            $lock->exclusively('second', static function () use (&$ran): void {
                $ran = true;
            });
        } catch (LockUnavailable) {
        }

        self::assertFalse($ran, 'exclusively() ran its section while another process held the lock');
        self::assertSame('theirs', $this->redis->get(self::lockKey('second')));
    }

    public function testATimedOutReleaseLeavesTheNextItemComputedOnceAndUnlocked(): void
    {
        $redis = $this->shifting();
        $pool = new RedisAdapter($this->redis, 'late-reply-test');
        $pool->setCallbackWrapper(new MemoLock($redis, lockTtlMs: 5_000, waitTimeoutMs: 20, pollIntervalMs: 5));

        $pool->get('first', static function (ItemInterface $item) use ($redis): string {
            $item->expiresAfter(60);
            $redis->failNext(['rawCommand', 'eval']);

            return 'first';
        });

        $before = $redis->count('set');
        $computed = 0;

        $value = $pool->get('second', static function (ItemInterface $item) use (&$computed): string {
            ++$computed;
            $item->expiresAfter(60);

            return 'second';
        });

        self::assertSame('second', $value);
        self::assertSame(1, $computed);
        self::assertSame(1, $redis->count('set') - $before, 'one SET NX took the lock');
        self::assertSame(0, $this->redis->exists(self::lockKey('second')), 'and the lock was handed back');
    }

    public function testAWaiterThatCanNeverTellGivesUpAfterTenAttempts(): void
    {
        // a connection whose every SET NX reads as refused, and which never
        // says whether the lock is there: the waiter would go round for ever
        $redis = new class extends \Redis {
            public int $sets = 0;

            /**
             * @param array<mixed>|null $options
             */
            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                if (100 < ++$this->sets) {
                    throw new \Error('the waiter is still going round');
                }

                return false;
            }

            /**
             * @param array<string>|string $key
             * @param string               ...$other_keys
             */
            public function exists(mixed $key, mixed ...$other_keys): \Redis|int|bool
            {
                return false;
            }
        };

        $pool = new RedisAdapter($this->redis, 'late-reply-test');
        $pool->setCallbackWrapper(new MemoLock($redis, lockTtlMs: 5_000, waitTimeoutMs: 20, pollIntervalMs: 1));

        $value = $pool->get('never', static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return 'computed anyway';
        });

        self::assertSame('computed anyway', $value);
        self::assertSame(10, $redis->sets, 'ten attempts, then the value is computed without the lock');
    }

    /**
     * A waiter that polls the lock, and one that listens for the wake-up and
     * asks how long the lock has left.
     *
     * @return iterable<string, array{bool}>
     */
    public static function waiters(): iterable
    {
        yield 'polling' => [false];
        yield 'listening' => [true];
    }

    #[DataProvider('waiters')]
    public function testAWaiterWithAShortWaitTimeoutWaitsForAHolderAtWork(bool $listens): void
    {
        // the holder works for a second and a half; each wait is 50 ms
        $this->holdLock('long', 1_500);
        $pool = new RedisAdapter($this->redis, 'late-reply-test');
        $pool->setCallbackWrapper(new MemoLock($this->redis, self::subscriberFactory($listens), lockTtlMs: 5_000, waitTimeoutMs: 50, pollIntervalMs: 10));
        $seen = null;

        $pool->get('long', function (ItemInterface $item) use (&$seen): string {
            $seen = $this->redis->get(self::lockKey('long'));

            return 'computed';
        });

        self::assertIsString($seen);
        self::assertNotSame('theirs', $seen, 'it computed under its own lock, once the holder was done, not beside it');
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function holders(): iterable
    {
        foreach (self::waiters() as $waiter => [$listens]) {
            // each holder fails after 40 ms, and the next one takes the lock
            yield 'holders at work, '.$waiter => [40, $listens];
            // each holder is done before the waiter looks
            yield 'holders done at once, '.$waiter => [0, $listens];
        }
    }

    #[DataProvider('holders')]
    public function testWaitersBehindHoldersThatKeepFailingTakeTheirTurn(int $holdsMs, bool $listens): void
    {
        // before each SET NX of this connection another process takes the
        // lock, fifteen times, as holders whose computation fails would
        $redis = new class($holdsMs) extends \Redis {
            public int $retakes = 15;

            public function __construct(private readonly int $holdsMs)
            {
                parent::__construct();
            }

            /**
             * @param array<mixed>|null $options
             */
            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $retaken = \is_array($options) && \in_array('NX', $options, true) && 0 < $this->retakes;

                if ($retaken) {
                    --$this->retakes;
                    // a holder done at once deletes its lock below; one at
                    // work lets it run out
                    parent::set($key, 'theirs', ['PX' => 0 === $this->holdsMs ? 30_000 : $this->holdsMs]);
                }

                $reply = null === $options ? parent::set($key, $value) : parent::set($key, $value, $options);

                if ($retaken && 0 === $this->holdsMs) {
                    // that holder is done before this one looks
                    parent::del($key);
                }

                return $reply;
            }
        };
        $this->connect($redis);

        $pool = new RedisAdapter($this->redis, 'late-reply-test');
        $pool->setCallbackWrapper(new MemoLock($redis, self::subscriberFactory($listens), lockTtlMs: 5_000, waitTimeoutMs: 5_000, pollIntervalMs: 5));
        $seen = null;

        $pool->get('turns', function (ItemInterface $item) use (&$seen): string {
            $seen = $this->redis->get(self::lockKey('turns'));

            return 'computed';
        });

        self::assertSame(0, $redis->retakes, 'it waited its turn behind every one of them');
        self::assertIsString($seen);
        self::assertNotSame('theirs', $seen, 'and computed under its own lock');
    }

    public function testExclusivelyGivesBackASetWhoseAnswerTimedOutAtOnce(): void
    {
        $redis = $this->shifting(readTimeout: 0.25);
        $lock = new MemoLock($redis, lockTtlMs: 30_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        // Redis runs the SET; its answer does not come within the read timeout
        $redis->failNext(['set'], stall: 0.25);
        self::expectUnavailable(static fn () => $lock->exclusively('landed', static fn (): string => 'ran'));

        self::assertSame(0, $this->redis->exists(self::lockKey('landed')), 'the lock the SET took was given back');
        self::assertSame([0.1, 0.25], $redis->readTimeouts, 'the give-back waited at most a slice for an answer, and the connection got its own read timeout back');
    }

    public function testTheWrapperGivesBackASetWhoseAnswerTimedOutOnceItHasComputed(): void
    {
        $redis = $this->shifting(readTimeout: 0.25);
        $pool = new RedisAdapter($this->redis, 'late-reply-test');
        $pool->setCallbackWrapper(new MemoLock($redis, lockTtlMs: 30_000, waitTimeoutMs: 20, pollIntervalMs: 5));

        $redis->failNext(['set'], stall: 0.25);

        $value = $pool->get('landed', static function (ItemInterface $item) use ($redis): string {
            $redis->sent[] = ['computed', 0.0];

            return 'computed';
        });

        self::assertSame('computed', $value);
        self::assertSame(0, $this->redis->exists(self::lockKey('landed')), 'the lock the SET took was given back');
        self::assertSame(['set', 'computed'], \array_slice(array_column($redis->sent, 0), 0, 2), 'after the computation, not before it');
        self::assertSame([0.1, 0.25], $redis->readTimeouts);
    }

    public function testOnceGivesBackASetWhoseAnswerTimedOutOnceItHasMadeTheThing(): void
    {
        $redis = $this->shifting(readTimeout: 0.25);
        $lock = new MemoLock($redis, lockTtlMs: 30_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        $redis->failNext(['set'], stall: 0.25);

        $made = $lock->once('landed', static fn (): mixed => null, static function () use ($redis): string {
            $redis->sent[] = ['made', 0.0];

            return 'made';
        });

        self::assertSame('made', $made);
        self::assertSame(0, $this->redis->exists(self::lockKey('landed')), 'the lock the SET took was given back');
        self::assertSame(['set', 'made'], \array_slice(array_column($redis->sent, 0), 0, 2), 'after the work, not before it');
    }

    public function testTheWrapperGivesBackASetWhoseAnswerTimedOutWhenTheComputationThrows(): void
    {
        $redis = $this->shifting(readTimeout: 0.25);
        $pool = new RedisAdapter($this->redis, 'late-reply-test');
        $pool->setCallbackWrapper(new MemoLock($redis, lockTtlMs: 30_000, waitTimeoutMs: 20, pollIntervalMs: 5));

        $redis->failNext(['set'], stall: 0.25);

        $thrown = null;

        try {
            $pool->get('landed', static fn (): string => throw new \DomainException('the computation failed'));
        } catch (\DomainException $e) {
            $thrown = $e->getMessage();
        }

        self::assertSame('the computation failed', $thrown);

        self::assertSame(0, $this->redis->exists(self::lockKey('landed')), 'the lock the SET took was given back');
    }

    public function testOnceGivesBackASetWhoseAnswerTimedOutWhenTheWorkThrows(): void
    {
        $redis = $this->shifting(readTimeout: 0.25);
        $lock = new MemoLock($redis, lockTtlMs: 30_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        $redis->failNext(['set'], stall: 0.25);

        $thrown = null;

        try {
            $lock->once('landed', static fn (): mixed => null, static fn (): string => throw new \DomainException('the work failed'));
        } catch (\DomainException $e) {
            $thrown = $e->getMessage();
        }

        self::assertSame('the work failed', $thrown);

        self::assertSame(0, $this->redis->exists(self::lockKey('landed')), 'the lock the SET took was given back');
    }

    public function testASetThatWasNeverSentIsNotGivenBack(): void
    {
        $redis = $this->shifting(readTimeout: 0.25);
        $lock = new MemoLock($redis, lockTtlMs: 30_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        // the connection could not be opened, after as long as the read
        // timeout: the SET never reached Redis
        $redis->failNext(['set'], stall: 0.25, sent: false);
        self::expectUnavailable(static fn () => $lock->exclusively('unsent', static fn (): string => 'ran'));

        self::assertSame(['set'], array_column($redis->sent, 0), 'no give-back: it would only try to connect once more');
        self::assertSame(0.0, self::giveBacksPausedUntil(), 'and nothing to pause');
    }

    /**
     * @return iterable<string, array{\Exception, bool}>
     */
    public static function failedCommands(): iterable
    {
        yield 'no answer in time' => [new \RedisException('read error on connection to 10.0.0.1:6379'), true];
        yield 'an answer cut short' => [new \RedisException('socket error on read socket'), true];
        yield 'no connection' => [new \RedisException('Redis server 10.0.0.1:6379 went away'), false];
        yield 'a connection lost before or after the write' => [new \RedisException('Connection lost'), false];
        yield 'an error the server answered with' => [new \RedisException('OOM command not allowed when used memory > \'maxmemory\'.'), false];
        yield 'no answer in time on a cluster' => [new \RedisClusterException('Error processing response from Redis node!'), true];
        yield 'no node reached on a cluster' => [new \RedisClusterException("Can't communicate with any node in the cluster"), false];
        yield 'redirected for too long on a cluster' => [new \RedisClusterException('Timed out attempting to find data in the correct node!'), false];
    }

    #[DataProvider('failedCommands')]
    public function testOnlyACommandWhoseAnswerCouldNotBeReadMayHaveRun(\Exception $failure, bool $mayHaveRun): void
    {
        self::assertSame($mayHaveRun, RedisCommand::readFailed($failure));
    }

    public function testAfterAGiveBackThatFailedNoneIsSentForAWhile(): void
    {
        $redis = $this->shifting(readTimeout: 0.25);
        $lock = new MemoLock($redis, lockTtlMs: 30_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        // the SET times out, and so does the give-back that follows it
        $redis->failNext(['set'], stall: 0.25);
        $redis->failNext(['rawCommand', 'eval']);
        self::expectUnavailable(static fn () => $lock->exclusively('first', static fn (): string => 'ran'));
        $sent = \count($redis->sent);

        // Redis is most likely down: the next timed-out SET is not given back
        $redis->failNext(['set'], stall: 0.25);
        self::expectUnavailable(static fn () => $lock->exclusively('second', static fn (): string => 'ran'));

        self::assertSame(['set'], array_column(\array_slice($redis->sent, $sent), 0), 'the SET, and no attempt to give it back');
    }

    public function testTheGiveBacksPauseForEveryLockInTheProcess(): void
    {
        $first = $this->shifting(readTimeout: 0.25);
        $second = $this->shifting(readTimeout: 0.25);

        // a give-back on one connection fails
        $first->failNext(['set'], stall: 0.25);
        $first->failNext(['rawCommand', 'eval']);
        self::expectUnavailable(static fn () => (new MemoLock($first, lockTtlMs: 30_000, waitTimeoutMs: 20))->exclusively('first', static fn (): string => 'ran'));

        // another lock, on another connection, sends none either
        $second->failNext(['set'], stall: 0.25);
        self::expectUnavailable(static fn () => (new MemoLock($second, lockTtlMs: 30_000, waitTimeoutMs: 20))->exclusively('second', static fn (): string => 'ran'));

        self::assertSame(['set'], array_column($second->sent, 0), 'the pause is the process\'s, not the connection\'s');
    }

    public function testGiveBacksAreSentAgainOnceThePauseIsOver(): void
    {
        $redis = $this->shifting(readTimeout: 0.25);
        $lock = new MemoLock($redis, lockTtlMs: 30_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        $redis->failNext(['set'], stall: 0.25);
        $redis->failNext(['rawCommand', 'eval']);
        self::expectUnavailable(static fn () => $lock->exclusively('first', static fn (): string => 'ran'));
        self::assertGreaterThan(microtime(true), self::giveBacksPausedUntil(), 'paused');

        // the pause runs out
        self::pauseGiveBacksUntil(microtime(true) - 0.001);

        $redis->failNext(['set'], stall: 0.25);
        self::expectUnavailable(static fn () => $lock->exclusively('second', static fn (): string => 'ran'));

        self::assertSame(0, $this->redis->exists(self::lockKey('second')), 'the lock the SET took was given back');
    }

    public function testAGiveBackThatFailedSlowlyPausesTheNextOnesTenTimesAsLong(): void
    {
        $redis = $this->shifting(readTimeout: 0.25);
        $lock = new MemoLock($redis, lockTtlMs: 30_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        // the give-back fails after 0.8 s, as a connect that times out would
        $redis->failNext(['set'], stall: 0.25);
        $redis->failNext(['rawCommand', 'eval'], stall: 0.8);
        self::expectUnavailable(static fn () => $lock->exclusively('slow', static fn (): string => 'ran'));

        self::assertGreaterThan(microtime(true) + 7.0, self::giveBacksPausedUntil(), 'ten times the 0.8 s it took, not the 5 s floor');
    }

    public function testAGiveBackLeavesAnotherHoldersLockAlone(): void
    {
        $redis = $this->shifting(readTimeout: 0.25);
        $lock = new MemoLock($redis, lockTtlMs: 30_000, waitTimeoutMs: 20, pollIntervalMs: 5);
        $this->holdLock('theirs', 30_000);

        $redis->failNext(['set'], stall: 0.25);
        self::expectUnavailable(static fn () => $lock->exclusively('theirs', static fn (): string => 'ran'));

        self::assertSame('theirs', $this->redis->get(self::lockKey('theirs')));
    }

    public function testTheWaitersAreWokenWhenRedisAnsweredTheDeleteWithAnError(): void
    {
        $redis = $this->shifting();
        $lock = new MemoLock($redis, lockTtlMs: 5_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        $lock->exclusively('woken', static function () use ($redis): void {
            $redis->failNext(['rawCommand', 'eval'], answer: "READONLY You can't write against a read only replica.");
        });

        self::assertSame([self::channel('woken')], $redis->published, 'the value is saved, so the waiters are woken to read it');
    }

    public function testTheWaitersAreNotWokenAfterADeleteThatGotNoAnswer(): void
    {
        $redis = $this->shifting();
        $lock = new MemoLock($redis, lockTtlMs: 5_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        $lock->exclusively('silent', static function () use ($redis): void {
            $redis->failNext(['rawCommand', 'eval']);
        });

        self::assertSame([], $redis->published, 'the connection broke: a wake-up would be a second attempt to reach Redis');
    }

    public function testAnErrorTheServerAnsweredWithKeepsTheConnection(): void
    {
        $redis = $this->shifting();
        $redis->set('late-reply-test:busykey', 'v');
        $dump = $redis->dump('late-reply-test:busykey');
        self::assertIsString($dump);

        try {
            // BUSYKEY, read in full: phpredis throws it
            RedisCommand::call($redis, static fn (): mixed => $redis->restore('late-reply-test:busykey', 0, $dump));
            self::fail('RESTORE onto a key that exists is refused');
        } catch (\RedisException $e) {
            self::assertStringStartsWith('BUSYKEY', $e->getMessage());
        }

        self::assertSame(0, $redis->closed, 'the connection is in step, so it stays open');
        self::assertSame('v', $redis->get('late-reply-test:busykey'));
    }

    public function testAnErrorLeftFromAnEarlierCommandIsNoAnswer(): void
    {
        $redis = $this->shifting();
        $this->leaveAnError($redis);

        try {
            RedisCommand::call($redis, static fn (): mixed => throw new \RedisException('read error on connection'));
        } catch (\RedisException) {
        }

        self::assertSame(1, $redis->closed, 'a broken read is closed, whatever error an earlier command left');
    }

    public function testTheLockLeavesTheLastErrorOfASharedConnectionAlone(): void
    {
        $redis = $this->shifting();
        $lock = new MemoLock($redis, lockTtlMs: 5_000, waitTimeoutMs: 20, pollIntervalMs: 5);

        // the first release learns which commands this server knows
        $lock->exclusively('dialect', static fn (): string => 'ran');
        $this->leaveAnError($redis);
        $error = $redis->getLastError();

        $lock->exclusively('dialect', static fn (): string => 'ran');

        self::assertNotNull($error);
        self::assertSame($error, $redis->getLastError(), 'what the application left there is still there');
    }

    public function testAClosedConnectionIsNotOpenedAgainOnlyToBeClosed(): void
    {
        $redis = $this->shifting();
        $redis->close();

        try {
            RedisCommand::call($redis, static fn (): mixed => throw new \RedisException('read error on connection'));
        } catch (\RedisException) {
        }

        // close() on a closed phpredis connection would open it first
        self::assertSame(1, $redis->closed, 'close() was not called again');
        self::assertFalse($redis->isConnected());
    }

    public function testTheLazyConnectionFromADsnReleasesItsLock(): void
    {
        // Symfony's lazy connection passes on only the methods it knows
        $lock = MemoLock::fromDsn(Connection::dsn(), lockTtlMs: 5_000, waitTimeoutMs: 20);

        self::assertSame('ran', $lock->exclusively('lazy', static fn (): string => 'ran'));
        self::assertSame(0, $this->redis->exists(self::lockKey('lazy')), 'the release went through');
    }

    public function holdLock(string $key, int $ms): void
    {
        $this->redis->set(self::lockKey($key), 'theirs', ['PX' => $ms]);
    }

    /**
     * @return (\Closure(): (\Redis|\Relay\Relay))|null
     */
    private static function subscriberFactory(bool $listens): ?\Closure
    {
        return $listens ? static fn (): \Redis|\Relay\Relay => Connection::openSingleNode() : null;
    }

    /**
     * How many connections the server has taken since it started.
     */
    private function shifting(float $readTimeout = 2.0): ShiftingRedis
    {
        return self::$kept[] = ShiftingRedis::fromDsn($readTimeout);
    }

    private function connect(\Redis $redis): void
    {
        $url = parse_url(Connection::dsn());
        $redis->connect(
            \is_array($url) && \is_string($url['host'] ?? null) ? $url['host'] : '127.0.0.1',
            \is_array($url) && \is_int($url['port'] ?? null) ? $url['port'] : 6379,
            1.0,
        );
    }

    /**
     * Leaves BUSYKEY as the connection's last error, as an application's own
     * command could.
     */
    private function leaveAnError(ShiftingRedis $redis): void
    {
        $redis->set('late-reply-test:busykey', 'v');
        $dump = $redis->dump('late-reply-test:busykey');
        self::assertIsString($dump);

        try {
            $redis->restore('late-reply-test:busykey', 0, $dump);
        } catch (\RedisException) {
        }
    }

    /**
     * After a give-back that failed, the process sends none for a while:
     * each test starts without that pause.
     */
    private static function resumeGiveBacks(): void
    {
        self::pauseGiveBacksUntil(0.0);
    }

    private static function pauseGiveBacksUntil(float $until): void
    {
        (new \ReflectionProperty(MemoLock::class, 'giveBacksPausedUntil'))->setValue(null, $until);
    }

    private static function giveBacksPausedUntil(): float
    {
        $until = (new \ReflectionProperty(MemoLock::class, 'giveBacksPausedUntil'))->getValue();
        self::assertIsFloat($until);

        return $until;
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function expectUnavailable(\Closure $call): void
    {
        try {
            $call();
            self::fail('LockUnavailable was expected');
        } catch (LockUnavailable) {
        }
    }

    private static function lockKey(string $key): string
    {
        return 'memolock:lock:'.self::tagFor($key);
    }

    private static function channel(string $key): string
    {
        return 'memolock:wake:'.self::tagFor($key);
    }

    private static function tagFor(string $key): string
    {
        return '{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
    }

    private function clean(): void
    {
        Connection::sweep($this->redis, 'memolock*');
        Connection::sweep($this->redis, 'late-reply-test*');
    }
}

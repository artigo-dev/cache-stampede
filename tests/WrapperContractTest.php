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
use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\AbstractTagAwareAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * What Symfony asks of a callback wrapper that saves the item itself:
 * $setMetadata($item) before the save, $setMetadata($item, $saved) after it
 * with what save() answered, the same item to both and to save(), and $save
 * set to false so that the pool does not save it a second time. Code that
 * hooks the save from inside the $setMetadata closure, keyed on the item,
 * relies on every part of it.
 *
 * A failed save that $setMetadata cannot pass on, because it takes the item
 * alone (Symfony 6.4 to 8.1) or there is none, leaves $save true instead:
 * the pool saves once more and reports that save itself.
 *
 * And the lock is handed back without ever costing the caller the value: a
 * release that throws is logged, and the lock left to expire.
 *
 * @phpstan-type Event array{0: string, 1: object, 2?: bool}
 */
final class WrapperContractTest extends TestCase
{
    /**
     * By name: nothing here depends on versioned-cache being installed.
     */
    private const VERSIONED = 'Artigo\\Cache\\Adapter\\VersionedRedisTagAwareAdapter';

    private \Redis|\Relay\Relay|null $redis = null;

    private ?string $namespace = null;

    protected function tearDown(): void
    {
        if (null !== $this->redis) {
            Connection::sweep($this->redis, 'memolock:*');

            if (null !== $this->namespace) {
                Connection::sweep($this->redis, $this->namespace.'*');
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrappers(): iterable
    {
        yield 'MemoLock' => ['memo'];
        yield 'FleetLock' => ['fleet'];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function wrappersAndSaves(): iterable
    {
        yield 'MemoLock, saved' => ['memo', false];
        yield 'MemoLock, refused' => ['memo', true];
        yield 'FleetLock, saved' => ['fleet', false];
        yield 'FleetLock, refused' => ['fleet', true];
    }

    #[DataProvider('wrappersAndSaves')]
    public function testTheWrapperReportsTheSaveThroughSetMetadata(string $wrapper, bool $refuse): void
    {
        $events = [];
        $save = true;

        [$value] = $this->call($wrapper, 'reported', $events, $save, $refuse);

        self::assertSame('computed', $value);
        self::assertSame(
            [['setMetadata'], ['save'], ['setMetadata', !$refuse]],
            array_map(static fn (array $event): array => [$event[0], ...\array_slice($event, 2)], $events),
            'once before the save with the item alone, once after it with what save() answered',
        );
        self::assertFalse($save, 'the wrapper saved the item, so the pool must not save it again');
    }

    /**
     * @return iterable<string, array{string, string, int, bool}>
     */
    public static function closuresAndSaves(): iterable
    {
        foreach (['MemoLock' => 'memo', 'FleetLock' => 'fleet'] as $name => $wrapper) {
            yield $name.', a closure that takes the item alone, saved' => [$wrapper, 'item', 0, false];
            yield $name.', a closure that takes the item alone, refused' => [$wrapper, 'item', \PHP_INT_MAX, true];
            yield $name.', no closure, saved' => [$wrapper, 'none', 0, false];
            yield $name.', no closure, refused' => [$wrapper, 'none', \PHP_INT_MAX, true];
            yield $name.', a closure that takes any number of arguments, refused' => [$wrapper, 'variadic', \PHP_INT_MAX, false];
        }
    }

    #[DataProvider('closuresAndSaves')]
    public function testARefusedSaveIsLeftToThePoolWhenSetMetadataCannotPassItOn(string $wrapper, string $closure, int $refusals, bool $saveAgain): void
    {
        $events = [];
        $save = true;
        $pool = self::pool($events, $refusals);

        $value = $this->wrapper($wrapper)->__invoke(
            static fn (): string => 'computed',
            $pool->getItem('left'),
            $save,
            $pool,
            match ($closure) {
                'item' => static function (ItemInterface $item): void {},
                'variadic' => static function (mixed ...$arguments): void {},
                default => null,
            },
        );

        self::assertSame('computed', $value);
        self::assertCount(1, $events, 'the wrapper saved once');
        self::assertSame($saveAgain, $save, $saveAgain ? 'nothing can pass the refusal on, so the pool saves once more' : 'saved, or the refusal was passed on: the pool must not save it again');
    }

    #[DataProvider('wrappers')]
    public function testARefusedSaveReachesTheCallerAsSaveFailed(string $wrapper): void
    {
        $events = [];
        $pool = new TagAwareAdapter(self::pool($events, refusals: \PHP_INT_MAX));
        $pool->setCallbackWrapper($this->wrapper($wrapper));

        $value = $pool->get('refused', static fn (): string => 'computed', metadata: $metadata);

        self::assertSame('computed', $value, 'a refused save still serves the value');
        self::assertCount(
            self::setMetadataTakesTheAnswer() ? 1 : 2,
            $events,
            'from 8.2 the wrapper passes the refusal on and its save is the only one; before, the pool saves once more',
        );

        // symfony/cache-contracts has the key from 3.8.1 on; before, nothing
        // can report the refusal
        if (\defined(ItemInterface::class.'::METADATA_SAVE_FAILED')) {
            self::assertIsArray($metadata);
            self::assertTrue($metadata[ItemInterface::METADATA_SAVE_FAILED] ?? false, 'the refusal reached the caller');
        }
    }

    #[DataProvider('wrappers')]
    public function testASaveRefusedOnceLandsWhenThePoolSavesOnceMore(string $wrapper): void
    {
        if (self::setMetadataTakesTheAnswer()) {
            self::markTestSkipped('From Symfony 8.2 the wrapper passes the refusal on, and the pool does not save again.');
        }

        $events = [];
        $pool = new TagAwareAdapter(self::pool($events, refusals: 1));
        $pool->setCallbackWrapper($this->wrapper($wrapper));

        $value = $pool->get('second', static fn (): string => 'computed', metadata: $metadata);

        self::assertSame('computed', $value);
        self::assertCount(2, $events, 'refused once, then saved by the pool');
        self::assertSame('computed', $pool->getItem('second')->get(), 'stored');

        if (\defined(ItemInterface::class.'::METADATA_SAVE_FAILED')) {
            self::assertIsArray($metadata);
            self::assertArrayNotHasKey(ItemInterface::METADATA_SAVE_FAILED, $metadata, 'the save that counts worked');
        }
    }

    #[DataProvider('wrappers')]
    public function testASaveThatWorksIsMadeOnce(string $wrapper): void
    {
        $events = [];
        $pool = new TagAwareAdapter(self::pool($events));
        $pool->setCallbackWrapper($this->wrapper($wrapper));

        $value = $pool->get('once', static fn (): string => 'computed');

        self::assertSame('computed', $value);
        self::assertCount(1, $events, 'saved by the wrapper, and not again by the pool');
        self::assertSame('computed', $pool->getItem('once')->get(), 'stored');
    }

    #[DataProvider('wrappers')]
    public function testAWriteTheGuardRefusedIsRefusedWhenThePoolSavesOnceMore(string $wrapper): void
    {
        if (!class_exists(self::VERSIONED)) {
            self::markTestSkipped('artigo/versioned-cache is not installed.');
        }

        $lock = $this->wrapper($wrapper);
        $this->redis ??= Connection::openSingleNode();
        $this->namespace = 'wrapper-guard'.bin2hex(random_bytes(3));

        // counts the writes that reach the pool's store
        $writes = new class extends DefaultMarshaller {
            public int $count = 0;

            /**
             * @param array<mixed>      $values
             * @param array<mixed>|null $failed
             *
             * @return array<mixed>
             */
            public function marshall(array $values, ?array &$failed): array
            {
                ++$this->count;

                return parent::marshall($values, $failed);
            }
        };
        $pool = $this->versioned($this->redis, $writes);
        $pool->setCallbackWrapper($lock);
        $elsewhere = $this->versioned(Connection::openSingleNode());

        $value = $pool->get('raced', static function (ItemInterface $item) use ($elsewhere): string {
            $item->expiresAfter(60);
            // another process deletes the key while this one computes it
            $elsewhere->deleteItem('raced');

            return 'computed across a delete';
        }, metadata: $metadata);

        self::assertSame('computed across a delete', $value, 'the caller still gets its value');
        self::assertSame(self::setMetadataTakesTheAnswer() ? 1 : 2, $writes->count, 'from 8.2 one write, before it one more from the pool');
        self::assertFalse($this->versioned(Connection::openSingleNode())->getItem('raced')->isHit(), 'refused every time: the delete stands');

        if (\defined(ItemInterface::class.'::METADATA_SAVE_FAILED')) {
            self::assertIsArray($metadata);
            self::assertTrue($metadata[ItemInterface::METADATA_SAVE_FAILED] ?? false, 'the refusal reached the caller');
        }
    }

    #[DataProvider('wrappers')]
    public function testTheSameItemGoesToSetMetadataAndToSave(string $wrapper): void
    {
        $events = [];
        $save = true;

        [, $item] = $this->call($wrapper, 'same-item', $events, $save);

        self::assertSame(
            array_fill(0, 3, spl_object_id($item)),
            array_map(static fn (array $event): int => spl_object_id($event[1]), $events),
            'one item instance, from the closure to the pool',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenReleases(): iterable
    {
        yield 'MemoLock, the client throws an Error while waking the waiters' => ['memo'];
        yield 'FleetLock, the store throws an exception on delete' => ['fleet-exception'];
        yield 'FleetLock, the store throws an Error on delete' => ['fleet-error'];
    }

    #[DataProvider('brokenReleases')]
    public function testAThrowingReleaseDoesNotLoseTheValue(string $release): void
    {
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

        $events = [];
        $pool = new TagAwareAdapter(self::pool($events));
        $pool->setLogger($logger);
        $pool->setCallbackWrapper(match ($release) {
            'memo' => $this->memoLockWhosePublishThrows(),
            'fleet-exception' => self::fleetLockWhoseDeleteThrows(new \RuntimeException('the store went away')),
            default => self::fleetLockWhoseDeleteThrows(new \Error('the store went away')),
        });

        $value = $pool->get('released', static fn (): string => 'computed');

        self::assertSame('computed', $value, 'the value reached the caller');
        self::assertCount(1, $logger->warnings, 'the failed release was logged');
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function brokenReadReleases(): iterable
    {
        yield 'the store throws an exception on delete' => [new \RuntimeException('the store went away')];
        yield 'the store throws an Error on delete' => [new \Error('the store went away')];
    }

    #[DataProvider('brokenReadReleases')]
    public function testAWaiterWhoseReleaseThrowsStillGetsTheValue(\Throwable $failure): void
    {
        $events = [];
        $pool = new TagAwareAdapter(self::pool($events));
        $pool->setCallbackWrapper(self::fleetLockWhoseDeleteThrows($failure, heldElsewhere: true));

        // every attempt waits on a read lock it then fails to hand back, and
        // the last one computes
        $value = $pool->get('waited', static fn (): string => 'computed');

        self::assertSame('computed', $value, 'the value reached the caller');
    }

    /**
     * Calls the wrapper the way Symfony does, with a $setMetadata closure that
     * records each call beside the saves the pool records.
     *
     * @param list<Event> $events
     *
     * @return array{mixed, ItemInterface}
     */
    private function call(string $wrapper, string $key, array &$events, bool &$save, bool $refuse = false): array
    {
        $pool = self::pool($events, $refuse ? \PHP_INT_MAX : 0);
        $item = $pool->getItem($key);

        $setMetadata = static function (ItemInterface $item, ?bool $saved = null) use (&$events): void {
            // whether a second argument was passed at all, not only its value
            $events[] = 1 === \func_num_args() ? ['setMetadata', $item] : ['setMetadata', $item, $saved ?? false];
        };

        $value = $this->wrapper($wrapper)->__invoke(
            static fn (): string => 'computed',
            $item,
            $save,
            $pool,
            $setMetadata,
        );

        return [$value, $item];
    }

    private function wrapper(string $wrapper): MemoLock|FleetLock
    {
        if ('fleet' === $wrapper) {
            return new FleetLock(new LockFactory(new InMemoryStore()));
        }

        $this->redis = Connection::openSingleNode();

        return new MemoLock($this->redis, lockTtlMs: 5_000);
    }

    /**
     * Whether symfony/cache hands the wrapper a $setMetadata closure that
     * takes what save() answered, which it does from 8.2 on.
     */
    private static function setMetadataTakesTheAnswer(): bool
    {
        $version = InstalledVersions::getVersion('symfony/cache');

        return null !== $version && version_compare($version, '8.2.0.0-dev', '>=');
    }

    /**
     * An in-memory pool that records every item it is asked to save, and
     * refuses the first $refusals of them.
     *
     * @param list<Event> $events
     */
    private static function pool(array &$events, int $refusals = 0): ArrayAdapter
    {
        $pool = new class extends ArrayAdapter {
            /** @var \Closure(CacheItemInterface): bool */
            public \Closure $onSave;

            public function save(CacheItemInterface $item): bool
            {
                return ($this->onSave)($item) && parent::save($item);
            }
        };

        $pool->onSave = static function (CacheItemInterface $item) use (&$events, &$refusals): bool {
            $events[] = ['save', $item];

            if (0 < $refusals) {
                --$refusals;

                return false;
            }

            return true;
        };

        return $pool;
    }

    /**
     * A versioned-cache pool in this test's namespace, whose invalidation
     * guard refuses a write computed across a delete.
     */
    private function versioned(\Redis|\Relay\Relay $redis, ?DefaultMarshaller $marshaller = null): AbstractTagAwareAdapter
    {
        $versioned = self::VERSIONED;

        /** @var AbstractTagAwareAdapter $pool */
        $pool = new $versioned($redis, (string) $this->namespace, marshaller: $marshaller, rulesCache: false); // @phpstan-ignore class.notFound (installed or not, see the test)

        return $pool;
    }

    private function memoLockWhosePublishThrows(): MemoLock
    {
        $this->redis = Connection::openSingleNode();

        if (!class_exists(\Redis::class, false)) {
            self::markTestSkipped('The failing client is an ext-redis one.');
        }

        // the lock is deleted, then waking the waiters fails with an \Error,
        // which no \Exception handler sees
        $client = new class extends \Redis {
            public function publish(string $channel, string $message): never
            {
                throw new \Error('the client failed while waking the waiters');
            }
        };
        $host = $this->redis->getHost();
        $port = $this->redis->getPort();
        self::assertIsString($host);
        self::assertIsInt($port);
        $client->connect($host, $port);

        return new MemoLock($client, lockTtlMs: 5_000);
    }

    private static function fleetLockWhoseDeleteThrows(\Throwable $failure, bool $heldElsewhere = false): FleetLock
    {
        // the lock is taken, then the store fails on the way back while it
        // still holds the key, so a second release would fail the same way.
        // Held elsewhere, only the waiters' read locks are taken.
        $store = new class($failure, $heldElsewhere) extends InMemoryStore {
            public function __construct(
                private readonly \Throwable $failure,
                private readonly bool $heldElsewhere,
            ) {
            }

            public function save(Key $key): void
            {
                if ($this->heldElsewhere) {
                    throw new LockConflictedException();
                }

                parent::save($key);
            }

            public function delete(Key $key): void
            {
                throw $this->failure;
            }
        };

        return new FleetLock(new LockFactory($store));
    }
}

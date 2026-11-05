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
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
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
 * And the lock is handed back without ever costing the caller the value: a
 * release that throws is logged, and the lock left to expire.
 *
 * @phpstan-type Event array{0: string, 1: object, 2?: bool}
 */
final class WrapperContractTest extends TestCase
{
    private \Redis|\Relay\Relay|null $redis = null;

    protected function tearDown(): void
    {
        if (null !== $this->redis) {
            Connection::sweep($this->redis, 'memolock:*');
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

    #[DataProvider('wrappers')]
    public function testARefusedSaveReachesTheCallerAsSaveFailed(string $wrapper): void
    {
        // the constant alone says nothing: it comes with symfony/cache-contracts,
        // which a symfony/cache that never sets it installs as well
        $version = InstalledVersions::getVersion('symfony/cache');

        if (null === $version || version_compare($version, '8.2.0.0-dev', '<')) {
            self::markTestSkipped('Symfony reports a failed save in $metadata from 8.2 on.');
        }

        $events = [];
        $pool = new TagAwareAdapter(self::pool($events, refuse: true));
        $pool->setCallbackWrapper($this->wrapper($wrapper));

        $value = $pool->get('refused', static fn (): string => 'computed', metadata: $metadata);

        self::assertSame('computed', $value, 'a refused save still serves the value');
        self::assertIsArray($metadata);
        self::assertTrue($metadata[ItemInterface::METADATA_SAVE_FAILED] ?? false, 'the refusal reached the caller');
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
        $pool = self::pool($events, $refuse);
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
     * An in-memory pool that records every item it is asked to save, and
     * refuses them all if told to.
     *
     * @param list<Event> $events
     */
    private static function pool(array &$events, bool $refuse = false): ArrayAdapter
    {
        $pool = new class extends ArrayAdapter {
            /** @var \Closure(CacheItemInterface): bool */
            public \Closure $onSave;

            public function save(CacheItemInterface $item): bool
            {
                return ($this->onSave)($item) && parent::save($item);
            }
        };

        $pool->onSave = static function (CacheItemInterface $item) use (&$events, $refuse): bool {
            $events[] = ['save', $item];

            return !$refuse;
        };

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

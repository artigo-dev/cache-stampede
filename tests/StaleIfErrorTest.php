<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\Stale\Record;
use Artigo\Cache\Stale\RefreshQueue;
use Artigo\Cache\StaleCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Past the time a value may be served while it is refreshed, it is computed
 * like a miss; the time kept for errors is what lets it be served when that
 * computation fails, and nothing else. A computation that failed is
 * remembered: until one succeeds, the kept value is served at once and one
 * process tries again every errorRetrySeconds.
 */
final class StaleIfErrorTest extends TestCase
{
    private ArrayAdapter $inner;

    private SpyElection $election;

    /** how many times the failing callback of down() was called */
    private int $failed = 0;

    protected function setUp(): void
    {
        $this->inner = new ArrayAdapter(0, false);
        $this->election = new SpyElection();
    }

    public function testAValuePastItsStaleTimeIsServedWhenTheComputationFails(): void
    {
        $this->store('old', microtime(true) - 70, 60, 600);

        $value = $this->pool(600)->get('k', self::failing(), metadata: $metadata);

        self::assertSame('old', $value);
        self::assertIsArray($metadata);
        self::assertTrue($metadata[StaleCache::METADATA_STALE] ?? false);
    }

    public function testAValuePastItsStaleTimeIsAMissWhenTheComputationSucceeds(): void
    {
        $this->store('old', microtime(true) - 70, 60, 600);

        self::assertSame('new', $this->pool(600)->get('k', static fn (): string => 'new'));
    }

    public function testAValuePastItsStaleTimeIsAMissThoughAnotherProcessHoldsTheElection(): void
    {
        // nothing has failed: the election is not asked, and the kept value
        // is not served in place of a computation that would succeed
        $this->store('old', microtime(true) - 70, 60, 600);
        $this->election->taken = true;

        self::assertSame('new', $this->pool(600)->get('k', static fn (): string => 'new'));
        self::assertSame([], $this->election->asked);
    }

    public function testStaleIfErrorKeepsTheValuePastItsStaleTime(): void
    {
        // written through the pool, so the pool decides how long it is kept
        $pool = $this->pool(600);
        $item = $pool->getItem('k')->set('old');
        $item->expiresAt(\DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', microtime(true) - 70)) ?: null);
        self::assertTrue($pool->save($item));

        self::assertSame('old', $pool->get('k', self::failing()));
    }

    public function testWithoutStaleIfErrorAFailingComputationThrows(): void
    {
        $this->store('old', microtime(true) - 70, 60, 0, keepFor: 3_600);

        $this->expectExceptionMessage('the origin is down');

        $this->pool(0)->get('k', self::failing());
    }

    public function testAValuePastTheTimeKeptForErrorsIsNoFallback(): void
    {
        $this->store('old', microtime(true) - 700, 60, 600, keepFor: 3_600);

        $this->expectExceptionMessage('the origin is down');

        $this->pool(600)->get('k', self::failing());
    }

    public function testAValuePastTheTimeKeptForErrorsIsNoFallbackThoughAFailureIsRemembered(): void
    {
        $this->store('old', microtime(true) - 700, 60, 600, keepFor: 3_600);
        $this->remember(microtime(true));

        $this->expectExceptionMessage('the origin is down');

        $this->pool(600)->get('k', self::failing());
    }

    public function testAValuePastItsStaleTimeIsAMissWhileItIsStillThere(): void
    {
        // the inner pool still holds it; its own times say it is done
        $this->store('old', microtime(true) - 70, 60, 0, keepFor: 3_600);
        $queue = new RefreshQueue();
        $pool = $this->pool(0);
        $pool->setDeferrer($queue);

        self::assertSame('new', $pool->get('k', static fn (): string => 'new'), 'computed now, not served stale');
        self::assertSame(0, $queue->count());
    }

    public function testAFailedComputationIsNotRepeatedByTheNextRead(): void
    {
        $this->store('old', microtime(true) - 70, 60, 600);
        $pool = $this->pool(600);

        self::assertSame('old', $pool->get('k', $this->down()), 'the first read computes, fails, and gets the kept value');
        self::assertSame(1, $this->failed);

        $began = microtime(true);
        $value = $pool->get('k', $this->down(50_000), metadata: $metadata);

        self::assertSame('old', $value);
        self::assertSame(1, $this->failed, 'the read right after it computes nothing');
        self::assertLessThan(0.04, microtime(true) - $began, 'and is served at once');
        self::assertIsArray($metadata);
        self::assertTrue($metadata[StaleCache::METADATA_STALE] ?? false);
        self::assertSame([], $this->election->asked, 'nobody is elected before the retry time has passed');
    }

    public function testOnceTheRetryTimeHasPassedOneProcessTriesAgain(): void
    {
        $this->store('old', microtime(true) - 70, 60, 600);
        $this->remember(microtime(true) - 5);

        self::assertSame('old', $this->pool(600)->get('k', $this->down()));
        self::assertSame(1, $this->failed, 'the winner of the election computed');
        self::assertSame(1, $this->election->released);

        $failedAt = $this->remembered();
        self::assertNotNull($failedAt, 'and its failure is remembered anew');
        self::assertGreaterThan(microtime(true) - 1, $failedAt);
    }

    public function testWhoeverLosesThatElectionServesTheKeptValueAtOnce(): void
    {
        $this->store('old', microtime(true) - 70, 60, 600);
        $this->remember(microtime(true) - 5);
        $this->election->taken = true;

        $value = $this->pool(600)->get('k', $this->down(), metadata: $metadata);

        self::assertSame('old', $value);
        self::assertSame(0, $this->failed, 'another process tries again; this one computes nothing');
        self::assertIsArray($metadata);
        self::assertTrue($metadata[StaleCache::METADATA_STALE] ?? false);
    }

    public function testWithADeferrerTheNextAttemptRunsAfterTheResponse(): void
    {
        $this->store('old', microtime(true) - 70, 60, 600);
        $this->remember(microtime(true) - 5);
        $queue = new RefreshQueue();
        $pool = $this->pool(600);
        $pool->setDeferrer($queue);

        self::assertSame('old', $pool->get('k', $this->down()), 'served at once');
        self::assertSame(0, $this->failed, 'nothing computed during the request');
        self::assertSame(1, $queue->count());

        $queue->flush();

        self::assertSame(1, $this->failed, 'one attempt after it');
    }

    public function testARetryThatSucceedsServesTheNewValue(): void
    {
        $this->store('old', microtime(true) - 70, 60, 600);
        $this->remember(microtime(true) - 5);

        $value = $this->pool(600)->get('k', static function (ItemInterface $item): string {
            $item->expiresAfter(300);

            return 'new';
        }, metadata: $metadata);

        self::assertSame('new', $value, 'without a deferrer the winner serves what it computed');
        self::assertIsArray($metadata);
        self::assertArrayNotHasKey(StaleCache::METADATA_STALE, $metadata);
    }

    public function testAFailureFromBeforeTheValueWasStoredDoesNotCount(): void
    {
        // the failure is older than the end of the value's lifetime: it was
        // an earlier value that could not be computed, and this one was
        // computed since
        $this->store('old', microtime(true) - 70, 60, 600);
        $this->remember(microtime(true) - 100);
        $this->election->taken = true;

        self::assertSame('new', $this->pool(600)->get('k', static fn (): string => 'new'), 'computed like a miss');
        self::assertSame([], $this->election->asked);
    }

    public function testWithNoRetryTimeTheNextReadTriesAgain(): void
    {
        $this->store('old', microtime(true) - 70, 60, 600);
        $pool = new StaleCache($this->inner, staleSeconds: 60, staleIfErrorSeconds: 600, election: $this->election, errorRetrySeconds: 0);

        self::assertSame('old', $pool->get('k', $this->down()));
        self::assertSame(1, $this->failed);
        self::assertSame('old', $pool->get('k', $this->down(1)));
        self::assertSame(2, $this->failed);

        $this->election->taken = true;

        self::assertSame('old', $pool->get('k', $this->down()));
        self::assertSame(2, $this->failed, 'but only the one elected');
    }

    public function testARequestThatWaitedBehindAFailedComputationComputesNothing(): void
    {
        // a pool that calls its callback wrapper, as ArrayAdapter does not
        $inner = new FilesystemAdapter('stale-test-'.bin2hex(random_bytes(4)));
        $inner->save($inner->getItem('k')->set(new Record('old', microtime(true) - 70, 60, 600))->expiresAfter(3_600));

        // the inner pool's lock: while this process waited for it, the holder
        // computed, failed, and remembered its failure
        $inner->setCallbackWrapper(static function (callable $callback, ItemInterface $item, bool &$save) use ($inner): mixed {
            $inner->save($inner->getItem(StaleCache::FAILED_PREFIX.hash('xxh128', 'k'))->set(microtime(true))->expiresAfter(31));

            return $callback($item, $save);
        });

        try {
            $pool = new StaleCache($inner, staleSeconds: 60, staleIfErrorSeconds: 600, election: $this->election);
            $value = $pool->get('k', $this->down(), metadata: $metadata);

            self::assertSame('old', $value);
            self::assertSame(0, $this->failed, 'the failing computation is not repeated');
            self::assertIsArray($metadata);
            self::assertTrue($metadata[StaleCache::METADATA_STALE] ?? false);

            $stored = $inner->getItem('k')->get();
            self::assertInstanceOf(Record::class, $stored);
            self::assertSame(70.0, round(microtime(true) - $stored->freshUntil), 'and the kept value is not stored again');
        } finally {
            $inner->clear();
        }
    }

    public function testAFailedRefreshOfAStaleValueIsRemembered(): void
    {
        // inside its stale time: served stale, refreshed inline, which fails
        $this->store('old', microtime(true) - 1, 60, 600);

        self::assertSame('old', $this->pool(600)->get('k', $this->down()));
        self::assertSame(1, $this->failed);
        self::assertNotNull($this->remembered());

        // the same value, now past its stale time: nobody has to compute to
        // find out that the origin is down
        $this->store('old', microtime(true) - 70, 60, 600);

        self::assertSame('old', $this->pool(600)->get('k', $this->down()));
        self::assertSame(1, $this->failed);
    }

    public function testAFailureIsRememberedForTheRetryTimeAndTheTimeARefreshMayTake(): void
    {
        $this->store('old', microtime(true) - 70, 60, 600);
        $pool = new StaleCache($this->inner, staleSeconds: 60, staleIfErrorSeconds: 600, election: $this->election, refreshLockTtlMs: 4_500, errorRetrySeconds: 2);

        $pool->get('k', $this->down());

        $expiry = $this->inner->getItem(self::failedKey())->getMetadata()[ItemInterface::METADATA_EXPIRY] ?? null;
        self::assertIsNumeric($expiry);
        self::assertEqualsWithDelta(microtime(true) + 2 + 5, (float) $expiry, 1.0);
    }

    public function testANegativeRetryTimeIsRefused(): void
    {
        $this->expectExceptionMessage('"$errorRetrySeconds" must not be negative');

        new StaleCache($this->inner, staleSeconds: 60, errorRetrySeconds: -1);
    }

    public function testAFailedComputationIsLoggedOnceAsAnError(): void
    {
        $logger = self::logger();
        $pool = $this->pool(600);
        $pool->setLogger($logger);
        $this->store('old', microtime(true) - 70, 60, 600);

        // past its stale time: computed, failed, logged; then remembered, so
        // the next read computes nothing and logs nothing
        $pool->get('k', $this->down());
        $pool->get('k', $this->down());

        self::assertSame(1, $this->failed);
        self::assertSame([['error', 'Computing "{key}" failed; its stale value is served: {message}']], $logger->records);
    }

    public function testAFailedRefreshIsLoggedAsAnError(): void
    {
        $logger = self::logger();
        $pool = $this->pool(600);
        $pool->setLogger($logger);

        // inside its stale time: served stale, refreshed inline, which fails
        $this->store('old', microtime(true) - 1, 60, 600);
        $pool->get('k', $this->down());

        self::assertSame([['error', 'Refreshing "{key}" failed; its stale value stays: {message}']], $logger->records);
    }

    /**
     * @return AbstractLogger&object{records: list<array{string, string}>}
     */
    private static function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            /**
             * @param string|\Stringable   $message
             * @param array<string, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [\is_string($level) ? $level : '', (string) $message];
            }
        };
    }

    private function pool(int $staleIfError): StaleCache
    {
        return new StaleCache($this->inner, staleSeconds: 60, staleIfErrorSeconds: $staleIfError, election: $this->election);
    }

    private static function failing(): \Closure
    {
        return static function (): never {
            throw new \RuntimeException('the origin is down');
        };
    }

    /**
     * A callback that counts its calls, takes $microseconds, and throws.
     */
    private function down(int $microseconds = 0): \Closure
    {
        return function () use ($microseconds): never {
            ++$this->failed;
            usleep($microseconds);

            throw new \RuntimeException('the origin is down');
        };
    }

    private function store(string $value, float $freshUntil, int $stale, int $error, ?int $keepFor = null): void
    {
        $record = new Record($value, $freshUntil, $stale, $error);
        $item = $this->inner->getItem('k')->set($record);
        $item->expiresAfter($keepFor ?? (int) ceil($record->keptUntil() - microtime(true)));
        $this->inner->save($item);
    }

    /**
     * Stores what a computation of 'k' that failed at $failedAt leaves.
     */
    private function remember(float $failedAt): void
    {
        $this->inner->save($this->inner->getItem(self::failedKey())->set($failedAt)->expiresAfter(31));
    }

    /**
     * When computing 'k' last failed, as the pool remembers it.
     */
    private function remembered(): ?float
    {
        $item = $this->inner->getItem(self::failedKey());
        $failedAt = $item->isHit() ? $item->get() : null;

        return \is_float($failedAt) ? $failedAt : null;
    }

    private static function failedKey(): string
    {
        return StaleCache::FAILED_PREFIX.hash('xxh128', 'k');
    }
}

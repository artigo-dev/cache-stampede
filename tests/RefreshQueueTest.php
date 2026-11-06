<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\Exception\InvalidArgumentException;
use Artigo\Cache\Stale\RefreshQueue;
use Artigo\Cache\Stale\TerminateSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The refreshes deferred until the response is out, and what runs them.
 */
final class RefreshQueueTest extends TestCase
{
    public function testFlushRunsEachOnceAndClears(): void
    {
        $queue = new RefreshQueue();
        $ran = [];
        $queue(static function () use (&$ran): void {
            $ran[] = 'first';
        });
        $queue(static function () use (&$ran, $queue): void {
            $ran[] = 'second';

            // one queued while the queue runs runs too
            $queue(static function () use (&$ran): void {
                $ran[] = 'third';
            });
        });

        $queue->flush();

        self::assertSame(['first', 'second', 'third'], $ran, 'in one flush');
        self::assertSame(0, $queue->count());

        $queue->flush();
        self::assertSame(['first', 'second', 'third'], $ran, 'and once');
    }

    public function testARefreshThatFlushesTheQueueRunsOnce(): void
    {
        $queue = new RefreshQueue();
        $runs = 0;
        $queue(static function () use (&$runs, $queue): void {
            // flushes on its first run only: run again, it would recurse for good
            if (1 === ++$runs) {
                $queue->flush();
            }
        }, 'k');

        $queue->flush();

        self::assertSame(1, $runs, 'taken off before it ran, so the flush inside it found nothing');
        self::assertSame(0, $queue->count());
    }

    public function testOneFailureKeepsNoOtherFromRunning(): void
    {
        $queue = new RefreshQueue();
        $ran = 0;
        $queue(static function (): never {
            throw new \RuntimeException('the first one failed');
        });
        $queue(static function () use (&$ran): void {
            ++$ran;
        });

        try {
            $queue->flush();
            self::fail('the failure is thrown');
        } catch (\RuntimeException $e) {
            self::assertSame('the first one failed', $e->getMessage());
        }

        self::assertSame(1, $ran, 'once the others have run');
        self::assertSame(0, $queue->count());
    }

    public function testOneRefreshIsQueuedPerKey(): void
    {
        $queue = new RefreshQueue();
        $ran = [];
        $refresh = static function (string $name) use (&$ran): \Closure {
            return static function () use (&$ran, $name): void {
                $ran[] = $name;
            };
        };

        $queue($refresh('a'), 'a');
        $queue($refresh('a again'), 'a');
        $queue($refresh('b'), 'b');

        // one without a key is always queued, and a numeric key is not taken
        // for the position of one
        $queue($refresh('unkeyed'));
        $queue($refresh('unkeyed again'));
        $queue($refresh('0'), '0');

        self::assertSame(5, $queue->count());

        $queue->flush();

        self::assertSame(['a', 'b', 'unkeyed', 'unkeyed again', '0'], $ran, 'the first one queued for a key runs');

        $queue($refresh('a after the flush'), 'a');
        self::assertSame(1, $queue->count(), 'a flushed key is queued again');
    }

    public function testResetFlushes(): void
    {
        $queue = new RefreshQueue();
        $ran = 0;
        $queue(static function () use (&$ran): void {
            ++$ran;
        }, 'k');

        self::assertInstanceOf(ResetInterface::class, $queue, 'what kernel.reset calls between messages');

        $queue->reset();

        self::assertSame(1, $ran);
        self::assertSame(0, $queue->count());
    }

    public function testAResetRunsEveryRefreshAndLogsWhatFailed(): void
    {
        $queue = new RefreshQueue();
        $queue->setLogger($logger = self::logger());
        $ran = 0;
        $queue(static function (): never {
            throw new \RuntimeException('the first one failed');
        }, 'failing');
        $queue(static function () use (&$ran): void {
            ++$ran;
        }, 'working');

        // a reset that throws would stop the worker
        $queue->reset();

        self::assertSame(1, $ran, 'the others ran');
        self::assertSame(0, $queue->count());
        self::assertSame(['A deferred refresh failed: {message}'], $logger->warnings, 'and the failure was logged');
    }

    public function testItHoldsNoMoreThanItsMaximum(): void
    {
        $queue = new RefreshQueue(maxRefreshes: 2);
        $queue->setLogger($logger = self::logger());
        $ran = [];
        $refresh = static function (string $name) use (&$ran): \Closure {
            return static function () use (&$ran, $name): void {
                $ran[] = $name;
            };
        };

        $queue($refresh('a'), 'a');
        $queue($refresh('b'), 'b');
        $queue($refresh('c'), 'c');
        $queue($refresh('unkeyed'));

        self::assertSame(2, $queue->count());
        self::assertCount(1, $logger->warnings, 'the first one turned away is logged, not every one');

        $queue->flush();

        self::assertSame(['a', 'b'], $ran, 'the ones queued first are kept');

        $queue($refresh('c'), 'c');
        $queue($refresh('d'), 'd');
        $queue($refresh('e'), 'e');

        self::assertSame(2, $queue->count(), 'emptied, it takes them again');
        self::assertCount(2, $logger->warnings, 'and logs the next one it turns away');
    }

    public function testTheMaximumIsPositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RefreshQueue(maxRefreshes: 0);
    }

    public function testTerminateFlushesOnKernelAndConsole(): void
    {
        $queue = new RefreshQueue();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new TerminateSubscriber($queue));
        $ran = 0;
        $refresh = static function () use (&$ran): void {
            ++$ran;
        };

        $queue($refresh);
        $dispatcher->dispatch(new \stdClass(), 'kernel.terminate');
        self::assertSame(1, $ran);

        $queue($refresh);
        $dispatcher->dispatch(new \stdClass(), 'console.terminate');
        self::assertSame(2, $ran);
    }

    /**
     * @return AbstractLogger&object{warnings: list<string>}
     */
    private static function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
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
    }
}

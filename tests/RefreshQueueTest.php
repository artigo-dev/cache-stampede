<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\Stale\RefreshQueue;
use Artigo\Cache\Stale\TerminateSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
}

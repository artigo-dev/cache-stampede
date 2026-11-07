<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Stale;

use Artigo\Cache\Exception\InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Collects the refreshes StaleCache puts off, and runs them after the
 * response has gone out, so that no visitor waits for them. It keeps one
 * refresh per key.
 *
 * Example: a page reads 25 stale fragments. Without a queue, the page waits
 * for 25 refreshes before it is sent. With this queue as the deferrer, the
 * page is sent with the stale fragments, and the 25 refreshes run
 * afterwards. A second stale read of the same fragment queues nothing more.
 *
 * Pass it to StaleCache::setDeferrer(). Something then has to run the queue:
 *
 * - In a Symfony application, TerminateSubscriber flushes it on
 *   kernel.terminate and console.terminate.
 * - A Messenger worker is one long command, so console.terminate comes only
 *   when it stops. Tag the queue kernel.reset, and reset() runs it after
 *   every message the worker handles.
 * - Without a kernel, flushAtShutdown() runs it when the script ends. Under
 *   PHP-FPM, call fastcgi_finish_request() before the script ends, or the
 *   client waits for the refreshes too.
 *
 * It holds $maxRefreshes at most. When it is full, a stale read queues no
 * refresh: the stale value is served as it is, and a read after the next
 * flush queues the refresh again.
 */
final class RefreshQueue implements ResetInterface, LoggerAwareInterface
{
    /**
     * The queued refreshes, in the order they were queued. A refresh with a
     * key is stored under 'key:' and that key; the prefix keeps a numeric key
     * from turning into an integer array key. A refresh without a key is
     * stored under the next integer.
     *
     * @var array<int|string, \Closure(): mixed>
     */
    private array $refreshes = [];

    private bool $atShutdown = false;

    /** whether the full queue turned a refresh away since it was last emptied */
    private bool $full = false;

    private ?LoggerInterface $logger = null;

    /**
     * @param int $maxRefreshes how many refreshes it holds at most
     */
    public function __construct(
        private readonly int $maxRefreshes = 1_000,
    ) {
        if ($maxRefreshes < 1) {
            throw new InvalidArgumentException(\sprintf('Argument "$maxRefreshes" must be a positive number, %d given.', $maxRefreshes));
        }
    }

    /**
     * Queues $refresh, unless a refresh for the same $key is already queued.
     * A second refresh of the same value would not make it fresh any sooner.
     * Without a key, the refresh is always queued.
     */
    public function __invoke(\Closure $refresh, ?string $key = null): void
    {
        $slot = null === $key ? null : 'key:'.$key;

        // a refresh for this key is already queued
        if (null !== $slot && isset($this->refreshes[$slot])) {
            return;
        }

        if (\count($this->refreshes) >= $this->maxRefreshes) {
            // full: log it only once until the queue is emptied, otherwise a
            // page of stale reads would log one line each
            if (!$this->full) {
                $this->full = true;
                $this->logger?->warning('The refresh queue holds {max} refreshes; until it is flushed, a stale value is served without one queued.', ['max' => $this->maxRefreshes, 'key' => $key]);
            }

            return;
        }

        if (null === $slot) {
            $this->refreshes[] = $refresh;
        } else {
            $this->refreshes[$slot] = $refresh;
        }
    }

    public function count(): int
    {
        return \count($this->refreshes);
    }

    /**
     * Runs every queued refresh once, including refreshes queued while it
     * runs, and empties the queue. If one throws, the others still run; the
     * first exception is thrown after all of them have run.
     */
    public function flush(): void
    {
        $failures = $this->run();

        if ([] !== $failures) {
            throw $failures[0];
        }
    }

    /**
     * Runs the queue at the end of a unit of work, for example between two
     * Messenger messages, through Symfony's services_resetter. A refresh that
     * fails is logged instead of thrown, because a reset that throws stops
     * the worker.
     */
    public function reset(): void
    {
        foreach ($this->run() as $failure) {
            $this->logger?->warning('A deferred refresh failed: {message}', ['message' => $failure->getMessage(), 'exception' => $failure]);
        }
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Flushes when the script ends, for an application without a kernel.
     */
    public function flushAtShutdown(): void
    {
        if (!$this->atShutdown) {
            $this->atShutdown = true;
            register_shutdown_function($this->flush(...));
        }
    }

    /**
     * Runs and removes every refresh. One that throws does not stop the
     * others.
     *
     * @return list<\Throwable> what they threw, in order
     */
    private function run(): array
    {
        $failures = [];

        // always take the first refresh left, so refreshes queued while this
        // runs are run too
        while (null !== $slot = array_key_first($this->refreshes)) {
            $refresh = $this->refreshes[$slot];

            // removed before it runs, so a flush() called from inside the
            // refresh does not run it a second time
            unset($this->refreshes[$slot]);

            try {
                $refresh();
            } catch (\Throwable $e) {
                $failures[] = $e;
            }
        }

        $this->full = false;

        return $failures;
    }
}

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
 * Holds the refreshes StaleCache defers until the response has gone out, one
 * per key.
 *
 * Given to StaleCache::setDeferrer(), it is flushed by TerminateSubscriber on
 * kernel.terminate and console.terminate, or at shutdown by
 * flushAtShutdown() where there is no kernel. A Messenger worker sends
 * console.terminate only when it stops: tagged kernel.reset, the queue is
 * flushed by reset() after every message the worker handles. Under PHP-FPM
 * without a kernel, call fastcgi_finish_request() before the script ends, or
 * the client waits for the refreshes too.
 *
 * It holds $maxRefreshes at most. Past that a refresh is not queued: its
 * stale value is served as it is, and a read after the next flush queues it.
 */
final class RefreshQueue implements ResetInterface, LoggerAwareInterface
{
    /**
     * In the order queued: one with a key under that key, prefixed so that a
     * numeric one stays a string, one without under the next integer.
     *
     * @var array<int|string, \Closure(): mixed>
     */
    private array $refreshes = [];

    private bool $atShutdown = false;

    /** whether a refresh was turned away since the queue was last emptied */
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
     * Queues $refresh, unless one for the same $key is queued already: a
     * second stale read of a value would renew it no sooner. Without a key
     * it is always queued.
     */
    public function __invoke(\Closure $refresh, ?string $key = null): void
    {
        $slot = null === $key ? null : 'key:'.$key;

        if (null !== $slot && isset($this->refreshes[$slot])) {
            return;
        }

        if (\count($this->refreshes) >= $this->maxRefreshes) {
            // once until the queue is emptied: a page of stale reads would
            // otherwise log one line each
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
     * Runs every refresh once, those queued while it runs included, and
     * empties the queue. One that throws does not keep the others from
     * running; the first failure is thrown once they all have.
     */
    public function flush(): void
    {
        $failures = $this->run();

        if ([] !== $failures) {
            throw $failures[0];
        }
    }

    /**
     * Flushes, for whatever ends a unit of work - Symfony's services_resetter
     * between Messenger messages. A refresh that fails is logged rather than
     * thrown: a reset that throws stops the worker.
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
     * Runs and removes every refresh; one that throws keeps no other from
     * running.
     *
     * @return list<\Throwable> what they threw, in order
     */
    private function run(): array
    {
        $failures = [];

        while (null !== $slot = array_key_first($this->refreshes)) {
            $refresh = $this->refreshes[$slot];

            // taken off before it runs: a flush() from inside it does not run it again
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

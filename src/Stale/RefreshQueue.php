<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Stale;

/**
 * Holds the refreshes StaleCache defers until the response has gone out.
 *
 * Given to StaleCache::setDeferrer(), it is flushed by TerminateSubscriber on
 * kernel.terminate and console.terminate, or at shutdown by
 * flushAtShutdown() where there is no kernel. Under PHP-FPM without a kernel,
 * call fastcgi_finish_request() before the script ends, or the client waits
 * for the refreshes too.
 */
final class RefreshQueue
{
    /** @var list<\Closure(): mixed> */
    private array $refreshes = [];

    private bool $atShutdown = false;

    public function __invoke(\Closure $refresh): void
    {
        $this->refreshes[] = $refresh;
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
        $failure = null;

        while ([] !== $this->refreshes) {
            $refresh = array_shift($this->refreshes);

            try {
                $refresh();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if (null !== $failure) {
            throw $failure;
        }
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
}

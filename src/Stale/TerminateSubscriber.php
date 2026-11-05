<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Stale;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Runs the deferred refreshes once the response is out: kernel.terminate
 * comes after Response::send(), which under PHP-FPM has already called
 * fastcgi_finish_request(), and console.terminate after a command.
 *
 * Needs symfony/event-dispatcher. The events are named, not imported, so
 * neither HttpKernel nor Console has to be installed.
 */
final class TerminateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RefreshQueue $queue,
    ) {
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        // last: whatever else listens has run, and the client is long gone
        return [
            'kernel.terminate' => ['flush', -1024],
            'console.terminate' => ['flush', -1024],
        ];
    }

    public function flush(): void
    {
        $this->queue->flush();
    }
}

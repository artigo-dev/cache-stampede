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
 * Runs the queued refreshes after the response has gone out, so the visitor
 * does not wait for them. kernel.terminate fires after Response::send(),
 * which under PHP-FPM has already called fastcgi_finish_request().
 * console.terminate fires after a console command.
 *
 * A Messenger worker is one long command, so console.terminate comes only
 * when it stops. Between its messages, the queue is run through its
 * kernel.reset tag (see RefreshQueue::reset()).
 *
 * Needs symfony/event-dispatcher. The events are written as strings, not
 * imported as classes, so neither HttpKernel nor Console has to be
 * installed.
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
        // priority -1024 runs it last, after every other listener, when the
        // client is long gone
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

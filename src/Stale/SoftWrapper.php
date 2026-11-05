<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Stale;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The callback wrapper a soft StaleCache puts on its inner pool. On a miss it
 * asks the pool for the value a tag invalidation reached and kept: a Record
 * written soft, invalidated less than its stale and error times ago, comes
 * back as it is - not saved again - its lifetime ended at the invalidation,
 * and StaleCache serves it stale and refreshes it. Anything else, and every
 * forced recomputation, goes to the wrapper it stands in front of: the lock.
 *
 * The pool is recognised by name, so this package needs nothing installed to
 * know it.
 *
 * @internal
 */
final class SoftWrapper
{
    /**
     * What a pool that keeps softly invalidated values implements.
     */
    public const AGED_ITEMS = 'Artigo\Cache\Adapter\AgedItemsInterface';

    public function __construct(
        private readonly \Closure $previous,
    ) {
    }

    public function __invoke(
        callable $callback,
        ItemInterface $item,
        bool &$save,
        CacheInterface $pool,
        ?\Closure $setMetadata = null,
        ?LoggerInterface $logger = null,
        ?float $beta = null,
    ): mixed {
        if (\INF !== $beta && self::recognises($pool) && null !== $record = self::aged($pool, $item->getKey())) {
            $save = false;

            return $record;
        }

        return ($this->previous)($callback, $item, $save, $pool, $setMetadata, $logger, $beta);
    }

    /**
     * Whether the pool keeps what soft rules reached: known by the name of
     * its interface, so this package installs nothing to know it.
     */
    public static function recognises(object $pool): bool
    {
        return is_a($pool, self::AGED_ITEMS);
    }

    /**
     * The Record the pool kept for $key, if it was written soft and is not
     * past the times it may be served in.
     */
    private static function aged(CacheInterface $pool, string $key): ?Record
    {
        $take = [$pool, 'takeAgedItem'];
        $aged = \is_callable($take) ? $take($key) : null;
        $record = \is_array($aged) ? $aged['value'] ?? null : null;
        $ageMs = \is_array($aged) ? $aged['ageMs'] ?? null : null;

        if (!$record instanceof Record || !$record->soft || !\is_int($ageMs) || $ageMs >= ($record->staleFor + $record->errorFor) * 1000) {
            return null;
        }

        return $record->endedAt(microtime(true) - $ageMs / 1000);
    }
}

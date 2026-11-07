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
 * The callback wrapper a soft StaleCache puts on its inner pool, in front of
 * the pool's lock.
 *
 * After a soft tag invalidation the inner pool answers a miss, but it keeps
 * the old value aside for a while (artigo/versioned-cache does this with
 * softGraceSeconds). On a miss, this wrapper asks the pool for that kept
 * value. It is used if it is a Record written by a soft pool, invalidated
 * less than its stale time plus its error time ago. Then the Record comes
 * back without being saved again, with its lifetime cut off at the moment
 * of the invalidation, and StaleCache serves it stale and refreshes it.
 *
 * Example: a menu fragment is fresh until 13:00, with $staleSeconds 60. Its
 * tag is invalidated softly at 12:30. A read at 12:30:20 gets the old menu,
 * served stale, and one refresh runs.
 *
 * Anything else, and every forced recompute (INF), goes on to the wrapper
 * behind this one: the lock.
 *
 * The pool is recognised by the name of its interface, so versioned-cache
 * does not have to be installed for this package to work.
 *
 * @internal
 */
final class SoftWrapper
{
    /**
     * The interface of a pool that keeps values after a soft invalidation.
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
        // not forced, and the pool kept a value after a soft invalidation:
        // hand that back as it is, without saving it again
        if (\INF !== $beta && self::recognises($pool) && null !== $record = self::aged($pool, $item->getKey())) {
            $save = false;

            return $record;
        }

        // anything else goes to the lock behind this wrapper
        return ($this->previous)($callback, $item, $save, $pool, $setMetadata, $logger, $beta);
    }

    /**
     * Whether the pool keeps values after a soft invalidation. It checks the
     * interface by name, so versioned-cache does not have to be installed.
     */
    public static function recognises(object $pool): bool
    {
        return is_a($pool, self::AGED_ITEMS);
    }

    /**
     * The Record the pool kept for $key after a soft invalidation, with its
     * lifetime ended at the invalidation. Null unless it was written by a
     * soft pool and invalidated less than its stale time plus its error time
     * ago.
     */
    private static function aged(CacheInterface $pool, string $key): ?Record
    {
        // the kept value, and how many milliseconds ago it was invalidated
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

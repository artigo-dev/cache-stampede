<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache;

/**
 * Where max_execution_time ends, as far as a wait is concerned.
 *
 * max_execution_time counts wall time on Windows, on Apple Silicon and on ZTS
 * builds with zend-max-execution-timers (FrankenPHP among them), so a waiter
 * that sleeps until its lock timeout can be killed mid-wait, with nothing
 * computed and nothing served. LockRegistry stops waiting a second before the
 * limit to leave time for computing the value; the locks here do the same.
 *
 * @internal
 */
final class Deadline
{
    /**
     * A second before max_execution_time runs out - or null when there is no
     * limit, or when it is already past, which means the timer counts CPU time
     * or was restarted by set_time_limit() and says nothing about the clock.
     */
    public static function beforeMaxExecutionTime(): ?float
    {
        if (0 >= $limit = (int) \ini_get('max_execution_time')) {
            return null;
        }

        $started = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $started = \is_float($started) || \is_int($started) ? (float) $started : microtime(true);
        $end = $started + $limit - 1.0;

        return microtime(true) < $end ? $end : null;
    }
}

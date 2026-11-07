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
 * Tells a waiting lock when to stop waiting, so that PHP's max_execution_time
 * does not kill the request in the middle of the wait.
 *
 * On Windows, on Apple Silicon and on ZTS builds with
 * zend-max-execution-timers (FrankenPHP among them), max_execution_time
 * counts real time, so time spent waiting counts too. A request that waits
 * for a lock can then be killed in the middle of the wait, with nothing
 * computed and nothing served. Example: max_execution_time is 30 seconds,
 * and at second 28 a request starts to wait for a lock, with a 5 second
 * timeout. PHP would kill it at second 30. With this deadline it stops
 * waiting at second 29 and computes the value instead. LockRegistry also
 * stops a second before the limit, and the locks here do the same.
 *
 * @internal
 */
final class Deadline
{
    /**
     * Returns the Unix time one second before max_execution_time runs out.
     *
     * Returns null when there is no limit. It also returns null when that
     * moment has already passed. That means the timer counts CPU time, or
     * set_time_limit() restarted it, so it tells us nothing about when the
     * request will be killed.
     */
    public static function beforeMaxExecutionTime(): ?float
    {
        if (0 >= $limit = (int) \ini_get('max_execution_time')) {
            return null;
        }

        // when the request started; now, if that is unknown
        $started = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $started = \is_float($started) || \is_int($started) ? (float) $started : microtime(true);
        $end = $started + $limit - 1.0;

        return microtime(true) < $end ? $end : null;
    }
}

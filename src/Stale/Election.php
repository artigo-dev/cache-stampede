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
 * Picks the one process that refreshes a stale value, without making anyone
 * wait: whoever does not win serves the stale value and moves on.
 */
interface Election
{
    /**
     * Takes the refresh of $key if nobody holds it, for at most $ttlMs.
     *
     * @return (\Closure(): void)|null the release, or null when another holds it
     *
     * @throws \Throwable when the lock cannot be reached; StaleCache then refreshes nothing
     *
     * @phpstan-impure
     */
    public function tryLock(string $key, int $ttlMs): ?\Closure;
}

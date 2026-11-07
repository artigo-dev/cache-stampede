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
 *
 * Example: twelve requests read the same stale value at once. One of them
 * wins and refreshes it; the other eleven serve the stale value and do not
 * wait. MemoLock and FleetLock pick one process across all servers.
 * FlockElection picks one per server.
 */
interface Election
{
    /**
     * Takes the right to refresh $key if nobody else holds it, for at most
     * $ttlMs.
     *
     * @return (\Closure(): void)|null a closure that releases it, or null when another process holds it
     *
     * @throws \Throwable when the lock cannot be reached; StaleCache then refreshes nothing
     *
     * @phpstan-impure
     */
    public function tryLock(string $key, int $ttlMs): ?\Closure;
}

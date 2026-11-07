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

/**
 * Picks the process that refreshes a stale value with flock() on a local
 * file. It needs no store, but it only sees one machine. So twelve servers
 * refresh a stale value twelve times, once each. That is still no stampede,
 * because every other request serves the stale value meanwhile. StaleCache
 * uses it when it is given no election.
 *
 * The keys share $slots lock files, so two keys can land on the same file.
 * While one of them is refreshed, a refresh of the other finds the file
 * locked and is skipped; a later read refreshes it. The lock has no TTL: it
 * is held until it is released or the process ends, so $ttlMs is not used.
 * A refresh that hangs in a long-running worker can therefore hold its file
 * for a long time.
 */
final class FlockElection implements Election
{
    public function __construct(
        private readonly ?string $directory = null,
        private readonly int $slots = 64,
    ) {
        if ($slots < 1) {
            throw new InvalidArgumentException(\sprintf('Argument "$slots" must be positive, %d given.', $slots));
        }
    }

    public function tryLock(string $key, int $ttlMs): ?\Closure
    {
        // one of the $slots files, picked by a hash of the key
        $file = \sprintf('%s/artigo-refresh-%d.lock', $this->directory ?? sys_get_temp_dir(), crc32($key) % $this->slots);
        $handle = @fopen($file, 'c');

        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Cannot open the lock file "%s".', $file));
        }

        // without blocking: a process that holds the file is refreshing this
        // key, or another key that shares the file
        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return static function () use ($handle): void {
            flock($handle, \LOCK_UN);
            fclose($handle);
        };
    }
}

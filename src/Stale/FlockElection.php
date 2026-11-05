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
 * An election through flock(): no store to run, and a scope of one machine.
 * N servers refresh a stale value N times - still no stampede, since each
 * serves the stale value meanwhile.
 *
 * The keys share $slots lock files, so two keys may share one: while one is
 * refreshed, the other is not, and is refreshed by a later read. The lock is
 * held until it is released or the process ends; $ttlMs is not needed.
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
        $file = \sprintf('%s/artigo-refresh-%d.lock', $this->directory ?? sys_get_temp_dir(), crc32($key) % $this->slots);
        $handle = @fopen($file, 'c');

        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Cannot open the lock file "%s".', $file));
        }

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

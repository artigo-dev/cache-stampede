<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\Stale\Election;

/**
 * An election that remembers what it was asked and what was handed back, and
 * loses every vote when told to.
 */
final class SpyElection implements Election
{
    public bool $taken = false;

    /** @var list<array{string, int}> */
    public array $asked = [];

    public int $released = 0;

    public function tryLock(string $key, int $ttlMs): ?\Closure
    {
        $this->asked[] = [$key, $ttlMs];

        if ($this->taken) {
            return null;
        }

        return function (): void {
            ++$this->released;
        };
    }
}

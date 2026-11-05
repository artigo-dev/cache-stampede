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
 * A value as StaleCache stores it: the value and its three times. Fresh until
 * $freshUntil; served while it is refreshed for $staleFor seconds after that;
 * kept for $errorFor seconds more, to be served only when computing it again
 * fails. The pool holding it keeps it for all three.
 *
 * The times travel inside the value, so any marshaller carries them - Deflate
 * included - and Symfony's own envelope, which knows one expiry, is left
 * alone. So does $soft: whether the pool that wrote it serves it stale after
 * a tag invalidation too (see SoftWrapper).
 *
 * @internal
 */
final class Record
{
    /**
     * @param float $freshUntil a Unix time with microseconds; INF for a value without a lifetime
     */
    public function __construct(
        public readonly mixed $value,
        public readonly float $freshUntil,
        public readonly float $staleFor,
        public readonly float $errorFor,
        public readonly bool $soft = false,
    ) {
    }

    /**
     * The same value, its lifetime ended at $at at the latest: what a tag
     * invalidation at $at leaves of it.
     */
    public function endedAt(float $at): self
    {
        return new self($this->value, min($this->freshUntil, $at), $this->staleFor, $this->errorFor, $this->soft);
    }

    public function isFresh(float $now): bool
    {
        return $now < $this->freshUntil;
    }

    /**
     * Whether it may still be served while a refresh runs.
     */
    public function isServable(float $now): bool
    {
        return $now < $this->freshUntil + $this->staleFor;
    }

    /**
     * Whether it may still be served when computing it again fails.
     */
    public function isKeptForErrors(float $now): bool
    {
        return $now < $this->freshUntil + $this->staleFor + $this->errorFor;
    }

    /**
     * When the pool may let it go: once it can no longer be served at all.
     */
    public function keptUntil(): float
    {
        return $this->freshUntil + $this->staleFor + $this->errorFor;
    }

    /**
     * @return array{v: mixed, f: float, s: float, e: float, o: bool}
     */
    public function __serialize(): array
    {
        return ['v' => $this->value, 'f' => $this->freshUntil, 's' => $this->staleFor, 'e' => $this->errorFor, 'o' => $this->soft];
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->value = $data['v'] ?? null;
        $this->freshUntil = self::time($data['f'] ?? null);
        $this->staleFor = self::time($data['s'] ?? null);
        $this->errorFor = self::time($data['e'] ?? null);
        $this->soft = true === ($data['o'] ?? null);
    }

    /**
     * A time read back, and a missing or mangled one as none at all: stale
     * at once and kept for nothing, so it is computed again.
     */
    private static function time(mixed $time): float
    {
        return \is_float($time) || \is_int($time) ? (float) $time : 0.0;
    }
}

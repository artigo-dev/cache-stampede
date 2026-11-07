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
 * A value as StaleCache stores it, together with its three times.
 *
 * - Until $freshUntil it is fresh.
 * - For $staleFor seconds after that it is stale: served at once while one
 *   process refreshes it.
 * - For $errorFor seconds after that it is kept for errors: served only when
 *   computing it again fails.
 *
 * Example: written at 12:00 with a 5 minute lifetime, $staleFor 60 and
 * $errorFor 600. It is fresh until 12:05, served stale until 12:06, and kept
 * for errors until 12:16. The pool that holds it keeps it until 12:16.
 *
 * The times are stored inside the value, so any marshaller carries them,
 * DeflateMarshaller included, and Symfony's own envelope, which has room for
 * only one expiry, is left alone. $soft is stored the same way: it says
 * whether the pool that wrote the value serves it stale after a tag
 * invalidation too (see SoftWrapper).
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
     * A copy whose lifetime ends at $at, or earlier if it already ended. This
     * is what a soft tag invalidation at $at leaves of the value: it is stale
     * from that moment.
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
     * When the pool may delete it: the moment it can no longer be served at
     * all.
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
     * Reads a time back from the stored data. A missing or broken time
     * becomes 0, so the value counts as long expired and is computed again.
     */
    private static function time(mixed $time): float
    {
        return \is_float($time) || \is_int($time) ? (float) $time : 0.0;
    }
}

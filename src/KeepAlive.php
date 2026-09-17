<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache;

use Artigo\Cache\Exception\LockUnavailable;

/**
 * Pushes a held lock's expiry out, for work that may outrun it.
 *
 * Handed to the closure doing the work, so the token stays where it belongs
 * and only whoever holds the lock can extend it:
 *
 *     $lock->exclusively('import', function (KeepAlive $keepAlive) use ($rows) {
 *         foreach ($rows as $row) {
 *             $this->import($row);
 *             $keepAlive();
 *         }
 *     });
 *
 * The extension is one command - `SET key token PX ttl IFEQ token`, Redis
 * 8.4 and Valkey 8.1, or a compare-and-PEXPIRE script on servers without it
 * (see RedisCommand) - which rewrites the lock only while it still carries
 * our token. A lock that expired and was taken by somebody else carries
 * theirs, and is left to them.
 *
 * It throws rather than answering false, for the reason
 * {@see MemoLock::exclusively()} throws and `symfony/lock`'s `refresh()` does:
 * carrying on after the lock has gone is the one outcome a caller who asked
 * for exclusivity cannot use, and a return value is too easy not to read.
 */
final class KeepAlive
{
    /**
     * @internal built by MemoLock, which is the only thing holding the token
     */
    public function __construct(
        private readonly \Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster|null $redis,
        private readonly string $key,
        private readonly string $lockKey,
        private readonly string $token,
        private readonly int $lockTtlMs,
    ) {
    }

    /**
     * For work running without a lock at all.
     *
     * {@see MemoLock::once()} does the work anyway when the lock cannot be
     * reached or never frees, because a duplicated render beats a failed
     * request. The closure still asks for a keep-alive; there is nothing to
     * keep alive, and nothing to lose by saying so quietly.
     */
    public static function unheld(string $key): self
    {
        return new self(null, $key, '', '', 0);
    }

    /**
     * @param int|null $ttlMs how much longer, in milliseconds; the lock's own TTL by default
     *
     * @throws LockUnavailable when the lock has expired, or cannot be reached
     */
    public function __invoke(?int $ttlMs = null): void
    {
        if (null === $this->redis) {
            // nothing is held: this work is running unprotected and knows it
            return;
        }

        try {
            $renewed = RedisCommand::expireIfEqual($this->redis, $this->lockKey, $this->token, $ttlMs ?? $this->lockTtlMs);
        } catch (\Exception $e) {
            throw new LockUnavailable(\sprintf('Could not extend the lock on "%s": %s', $this->key, $e->getMessage()), 0, $e);
        }

        if (!$renewed) {
            throw new LockUnavailable(\sprintf('The lock on "%s" is no longer held; it expired while the work was running.', $this->key));
        }
    }
}

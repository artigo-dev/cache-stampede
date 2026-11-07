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
 * Lets work that holds a lock push the lock's expiry further out.
 *
 * A lock expires after its TTL, so that a holder that dies does not block
 * everybody forever. But long work can outlive the TTL. Example: an import
 * holds a 30 second lock but needs two minutes. After 30 seconds the lock
 * expires, and the next worker starts the same import beside it. With a
 * KeepAlive the work calls it as it goes, and each call moves the expiry a
 * full TTL out from now.
 *
 * It is handed to the closure doing the work. So the lock's token stays in
 * here, and only the holder can extend the lock:
 *
 *     $lock->exclusively('import', function (KeepAlive $keepAlive) use ($rows) {
 *         foreach ($rows as $row) {
 *             $this->import($row);
 *             $keepAlive();
 *         }
 *     });
 *
 * The extension is one command: `SET key token PX ttl IFEQ token` on Redis
 * 8.4 and Valkey 8.1, or on older servers a small script that compares the
 * token and then runs PEXPIRE (see RedisCommand). Either way the lock is
 * changed only while it still holds our token. If it expired and somebody
 * else took it, it holds their token, and it is left alone.
 *
 * It throws when the lock is gone, instead of returning false. That is the
 * same reason {@see MemoLock::exclusively()} throws and `symfony/lock`'s
 * `refresh()` does: a caller who asked to run alone cannot use a run that
 * carries on without the lock, and a return value is too easy to ignore.
 */
final class KeepAlive
{
    /**
     * @internal created by MemoLock, the only code that knows the lock's token
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
     * A KeepAlive for work that runs without any lock.
     *
     * {@see MemoLock::once()} still does the work when the lock cannot be
     * reached or is never released, because rendering something twice is
     * better than a failed request. The closure may still call its KeepAlive.
     * There is no lock to extend, so the call quietly does nothing.
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
            // no lock is held: this work already runs without one, and knows it
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

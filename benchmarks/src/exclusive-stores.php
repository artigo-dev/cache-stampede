<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Two stores that hide their shared-lock support, so that FleetLock's waiters
 * take the exclusive lock instead of the read lock: the Lock component falls
 * back to it for a store that is not a SharedLockStoreInterface. Used by the
 * fleetlock-exclusive and fleetlock-pubsub-exclusive modes of stampede.php.
 */

namespace Artigo\Cache\Benchmarks;

use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * A store its waiters poll, as RedisStore's do, on the exclusive lock.
 */
final class ExclusiveStore implements PersistingStoreInterface
{
    public function __construct(
        private readonly PersistingStoreInterface $store,
    ) {
    }

    public function save(Key $key): void
    {
        $this->store->save($key);
    }

    public function delete(Key $key): void
    {
        $this->store->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->store->putOffExpiration($key, $ttl);
    }
}

/**
 * A store its waiters block on, as PubSubRedisStore's do, on the exclusive
 * lock.
 */
final class ExclusiveBlockingStore implements BlockingStoreInterface
{
    public function __construct(
        private readonly BlockingStoreInterface $store,
    ) {
    }

    public function save(Key $key): void
    {
        $this->store->save($key);
    }

    public function waitAndSave(Key $key): void
    {
        $this->store->waitAndSave($key);
    }

    public function delete(Key $key): void
    {
        $this->store->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->store->putOffExpiration($key, $ttl);
    }
}

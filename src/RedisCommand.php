<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache;

/**
 * Sends a command the client has no method for yet.
 *
 * The lock is built on two Redis 8.4 additions - `SET ... IFEQ` to extend it
 * only while it is still ours, `DELEX ... IFEQ` to release it on the same
 * condition - and the clients spell those as raw commands. A cluster routes a
 * raw command by the key it is handed first; a RedisArray has to be asked for
 * the node that owns the key.
 *
 * @internal
 */
final class RedisCommand
{
    /**
     * Answers whatever the server answered, or false with the client's last
     * error set when it refused the command.
     */
    public static function raw(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $command, string ...$arguments): mixed
    {
        if ($redis instanceof \RedisArray) {
            $node = $redis->_instance($redis->_target($key));

            if (!$node instanceof \Redis) {
                return false;
            }

            $redis = $node;
        }

        if ($redis instanceof \RedisCluster || $redis instanceof \Relay\Cluster) {
            return $redis->rawCommand($key, $command, $key, ...$arguments);
        }

        return $redis->rawCommand($command, $key, ...$arguments);
    }
}

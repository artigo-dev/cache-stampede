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
 * The two conditional commands the lock is built on, in whatever dialect the
 * server speaks.
 *
 * Extending the lock only while it still carries our token, and releasing it
 * on the same condition, are one command each on Redis 8.4 - `SET ... IFEQ`
 * and `DELEX ... IFEQ` - and on Valkey, which has `SET ... IFEQ` since 8.1 and
 * spells the release `DELIFEQ` (9.0). Older servers have neither, and get a
 * compare-and-set script instead: the same round trip, one EVAL.
 *
 * Which dialect a connection speaks is learnt from its first refusal - an
 * unknown command or an unknown option is a `false` reply with the client's
 * last error on phpredis, an exception on Relay - and remembered for the
 * connection, so the fallback costs one failed command per connection, once.
 *
 * A cluster routes a raw command by the key it is handed first; a RedisArray
 * has to be asked for the node that owns the key.
 *
 * @internal
 */
final class RedisCommand
{
    private const RELEASE_SCRIPT = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            return redis.call('DEL', KEYS[1])
        end

        return 0
        LUA;

    private const EXTEND_SCRIPT = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            return redis.call('PEXPIRE', KEYS[1], ARGV[2])
        end

        return 0
        LUA;

    /**
     * How many of the native forms each connection has refused, per family,
     * so that a refusal is paid for once.
     *
     * @var array<string, array<int, int>>
     */
    private static array $refused = [];

    /**
     * Deletes the lock only if it still carries our token. True when it did,
     * and is gone.
     */
    public static function deleteIfEqual(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $token): bool
    {
        $reply = self::conditional($redis, $key, 'release', [
            ['DELEX', 'IFEQ', $token],
            ['DELIFEQ', $token],
        ], self::RELEASE_SCRIPT, [$token]);

        // an integer reply, or its literal form on a client that keeps replies raw
        return 1 === $reply || '1' === $reply;
    }

    /**
     * Rewrites the lock's expiry only while it still carries our token. True
     * when it did, and the lock lives on.
     */
    public static function expireIfEqual(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $token, int $ttlMs): bool
    {
        $reply = self::conditional($redis, $key, 'extend', [
            ['SET', $token, 'PX', (string) $ttlMs, 'IFEQ', $token],
        ], self::EXTEND_SCRIPT, [$token, (string) $ttlMs]);

        // SET answers OK (true on phpredis unless replies are kept raw), the script 1
        return true === $reply || 'OK' === $reply || 1 === $reply || '1' === $reply;
    }

    /**
     * Sends a command the client has no method for. Answers whatever the
     * server answered, or false with the client's last error set when it
     * refused the command.
     */
    public static function raw(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $command, string ...$arguments): mixed
    {
        $node = self::node($redis, $key);

        if (null === $node) {
            return false;
        }

        if ($node instanceof \RedisCluster || $node instanceof \Relay\Cluster) {
            return $node->rawCommand($key, $command, $key, ...$arguments);
        }

        return $node->rawCommand($command, $key, ...$arguments);
    }

    /**
     * The native forms, best first, then the script: the first form the
     * server does not refuse answers, and its refusals are remembered.
     *
     * @param list<list<string>> $forms           each a command and its arguments after the key
     * @param list<string>       $scriptArguments
     */
    private static function conditional(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $family, array $forms, string $script, array $scriptArguments): mixed
    {
        $id = spl_object_id($redis);
        $refused = self::$refused[$family][$id] ?? 0;

        for ($i = $refused; $i < \count($forms); ++$i) {
            [$spoken, $reply] = self::attempt($redis, $key, $forms[$i]);

            if ($spoken) {
                return $reply;
            }

            self::$refused[$family][$id] = $i + 1;
        }

        $node = self::node($redis, $key);

        if (null === $node) {
            return false;
        }

        return $node->eval($script, [$key, ...$scriptArguments], 1);
    }

    /**
     * One native form: [true, the reply] when the server spoke it, [false,
     * null] when it did not know the command or the option.
     *
     * @param list<string> $form
     *
     * @return array{bool, mixed}
     */
    private static function attempt(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, array $form): array
    {
        $node = self::node($redis, $key);

        if (null === $node) {
            return [true, false];
        }

        try {
            $reply = self::raw($redis, $key, ...$form);
        } catch (\Exception $e) {
            if (self::isRefusal($e->getMessage())) {
                return [false, null];
            }

            throw $e;
        }

        if (false === $reply) {
            $error = $node->getLastError();

            if (\is_string($error) && self::isRefusal($error)) {
                $node->clearLastError();

                return [false, null];
            }
        }

        return [true, $reply];
    }

    /**
     * "ERR unknown command" is a server without the command; "ERR syntax
     * error" is a SET without the IFEQ option. Anything else is an answer.
     */
    private static function isRefusal(string $error): bool
    {
        return false !== stripos($error, 'unknown command') || false !== stripos($error, 'syntax error');
    }

    /**
     * Whom to talk to for a key: the node that owns it on a RedisArray, the
     * connection itself everywhere else.
     */
    private static function node(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key): \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster|null
    {
        if (!$redis instanceof \RedisArray) {
            return $redis;
        }

        $node = $redis->_instance($redis->_target($key));

        return $node instanceof \Redis ? $node : null;
    }
}

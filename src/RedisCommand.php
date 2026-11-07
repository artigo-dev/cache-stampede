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
 * Sends the two lock commands that act only while the lock still holds our
 * token: extend the lock, and delete it. Different server versions need
 * different commands for this.
 *
 * Redis 8.4 has one command for each: `SET ... IFEQ` to extend and
 * `DELEX ... IFEQ` to delete. Valkey has `SET ... IFEQ` since 8.1, and calls
 * the delete `DELIFEQ` (since 9.0). Older servers have neither, so they get a
 * small script that compares the token and then acts. That is still one
 * round trip, one EVAL.
 *
 * Which commands a server knows is learnt from its refusals. phpredis
 * answers an unknown command or option with `false` and sets the client's
 * last error; Relay throws an exception. Each refusal is remembered for that
 * connection, so each native command fails at most once per connection.
 * Example: on Redis 7.2 the first release sends DELEX, which is refused,
 * then DELIFEQ, which is refused, then the script. Every later release on
 * that connection sends the script straight away.
 *
 * On a cluster, a raw command is routed by the key passed first. A
 * RedisArray has to be asked which node owns the key.
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
     * For each family ('release', 'extend') and each connection (by object
     * id), how many of the native forms the server has refused. Later calls
     * skip those forms, so each refusal happens only once.
     *
     * @var array<string, array<int, int>>
     */
    private static array $refused = [];

    /**
     * Deletes the lock, but only if it still holds our token. Returns true
     * when it deleted it.
     */
    public static function deleteIfEqual(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $token): bool
    {
        $reply = self::conditional($redis, $key, 'release', [
            ['DELEX', 'IFEQ', $token],
            ['DELIFEQ', $token],
        ], self::RELEASE_SCRIPT, [$token]);

        // the integer 1, or the string '1' from a client that keeps replies raw
        return 1 === $reply || '1' === $reply;
    }

    /**
     * Sets a new expiry on the lock, but only if it still holds our token.
     * Returns true when it did, so the lock is still ours.
     */
    public static function expireIfEqual(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $token, int $ttlMs): bool
    {
        $reply = self::conditional($redis, $key, 'extend', [
            ['SET', $token, 'PX', (string) $ttlMs, 'IFEQ', $token],
        ], self::EXTEND_SCRIPT, [$token, (string) $ttlMs]);

        // SET answers OK, which phpredis turns into true unless it keeps
        // replies raw; the script answers 1
        return true === $reply || 'OK' === $reply || 1 === $reply || '1' === $reply;
    }

    /**
     * Sends a command the client has no method for. Returns what the server
     * answered, or false (with the client's last error set) when the server
     * refused the command. Also false when no node owns the key.
     */
    public static function raw(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $command, string ...$arguments): mixed
    {
        $node = self::node($redis, $key);

        if (null === $node) {
            return false;
        }

        // a cluster client takes the key first, to pick the node
        if ($node instanceof \RedisCluster || $node instanceof \Relay\Cluster) {
            return $node->rawCommand($key, $command, $key, ...$arguments);
        }

        return $node->rawCommand($command, $key, ...$arguments);
    }

    /**
     * Tries the native forms in order, best first, then the script. The first
     * form the server accepts gives the answer. Each refused form is
     * remembered, so later calls on this connection skip it.
     *
     * @param list<list<string>> $forms           each a command and its arguments after the key
     * @param list<string>       $scriptArguments
     */
    private static function conditional(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $family, array $forms, string $script, array $scriptArguments): mixed
    {
        // start after the forms this connection has already refused
        $id = spl_object_id($redis);
        $refused = self::$refused[$family][$id] ?? 0;

        for ($i = $refused; $i < \count($forms); ++$i) {
            [$spoken, $reply] = self::attempt($redis, $key, $forms[$i]);

            if ($spoken) {
                return $reply;
            }

            // refused: the next call starts with the form after this one
            self::$refused[$family][$id] = $i + 1;
        }

        // every native form was refused: use the script
        $node = self::node($redis, $key);

        if (null === $node) {
            return false;
        }

        return $node->eval($script, [$key, ...$scriptArguments], 1);
    }

    /**
     * Sends one native form. Returns [true, the reply] when the server
     * accepted it, and [false, null] when the server did not know the
     * command or the option.
     *
     * @param list<string> $form
     *
     * @return array{bool, mixed}
     */
    private static function attempt(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, array $form): array
    {
        $node = self::node($redis, $key);

        // no node owns the key: answer false, as a failed command would,
        // without trying the next form
        if (null === $node) {
            return [true, false];
        }

        try {
            $reply = self::raw($redis, $key, ...$form);
        } catch (\Exception $e) {
            // Relay throws on a refusal; any other error is a real failure
            if (self::isRefusal($e->getMessage())) {
                return [false, null];
            }

            throw $e;
        }

        // phpredis answers a refusal with false and sets the last error.
        // Clear the error, so it does not linger on the connection
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
     * Whether an error means the server does not know the command. "ERR
     * unknown command" comes from a server without the command; "ERR syntax
     * error" from a SET that does not know the IFEQ option. Any other error
     * counts as the server's answer, not as a refusal.
     */
    private static function isRefusal(string $error): bool
    {
        return false !== stripos($error, 'unknown command') || false !== stripos($error, 'syntax error');
    }

    /**
     * The connection to send a key's command to: on a RedisArray, the node
     * that owns the key; everywhere else, the connection itself. Null when
     * the RedisArray has no node for the key.
     */
    private static function node(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key): \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster|null
    {
        if (!$redis instanceof \RedisArray) {
            return $redis;
        }

        $host = $redis->_target($key);
        $node = \is_string($host) ? $redis->_instance($host) : null;

        return $node instanceof \Redis ? $node : null;
    }
}

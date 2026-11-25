<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache;

use Psr\Log\LoggerInterface;

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
 * A native command goes through phpredis's own method for it where there is
 * one (see typed()), otherwise as a raw command. On a cluster, a raw command
 * is routed by the key passed first, and one that is redirected is sent again
 * as the script, which follows the redirect; for a while after a node turned
 * out to be down, only the raw form goes out (see attempt()). A RedisArray
 * has to be asked which node owns the key.
 *
 * Every command the package sends goes through call(), which closes a
 * phpredis connection whose command failed in a way that can leave a late
 * answer on it (see there).
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
     * A command that failed after this share of the read timeout, or later,
     * ran into that timeout. The rest is a margin for the timers.
     */
    private const TIMED_OUT = 0.9;

    /**
     * After an attempt that failed against a server that is most likely
     * down, how long this process makes no such attempt again: at least
     * PAUSE_S, and PAUSE_TIMES as long as the failed attempt took, so that
     * slow failures do not come round sooner than they cost.
     */
    private const PAUSE_S = 5;
    private const PAUSE_TIMES = 10;

    /**
     * Until when this process sends the conditional forms to a cluster only
     * raw, with no script after a redirect (see attempt()), as a Unix time.
     * Process-wide: one node that is down pauses them for every connection.
     */
    private static float $detoursPausedUntil = 0.0;

    /**
     * For each family ('release', 'extend') and each connection (by object
     * id), how many of the native forms the server has refused. Later calls
     * skip those forms, so each refusal happens only once.
     *
     * @var array<string, array<int, int>>
     */
    private static array $refused = [];

    /**
     * The cluster connections the warning about replica reads has been
     * logged for: once per connection is enough.
     *
     * @var \WeakMap<\RedisCluster, true>|null
     */
    private static ?\WeakMap $warnedAboutReplicas = null;

    /**
     * Deletes the lock, but only if it still holds our token. Returns true
     * when it deleted it.
     */
    public static function deleteIfEqual(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $token, ?LoggerInterface $logger = null): bool
    {
        $reply = self::conditional($redis, $key, 'release', ['DELEX', 'DELIFEQ'], $token, 0, self::RELEASE_SCRIPT, [$token], $logger);

        // the integer 1, or the string '1' from a client that keeps replies raw
        return 1 === $reply || '1' === $reply;
    }

    /**
     * Sets a new expiry on the lock, but only if it still holds our token.
     * Returns true when it did, so the lock is still ours.
     */
    public static function expireIfEqual(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $token, int $ttlMs, ?LoggerInterface $logger = null): bool
    {
        $reply = self::conditional($redis, $key, 'extend', ['SET IFEQ'], $token, $ttlMs, self::EXTEND_SCRIPT, [$token, (string) $ttlMs], $logger);

        // SET answers OK, which phpredis turns into true unless it keeps
        // replies raw; the script answers 1
        return true === $reply || 'OK' === $reply || 1 === $reply || '1' === $reply;
    }

    /**
     * Runs $command, which talks to Redis through $redis, and closes the
     * connection when phpredis throws and may have left an answer on it,
     * before the exception goes on. $raw says that $command is a raw command.
     *
     * phpredis before 6.4 can keep a connection open after a read timed out
     * or a reply was cut short. The reply that comes late is then read as the
     * answer to the next command, and every answer after it is one behind
     * until the connection closes: a SET NX that took the lock reads as
     * refused, and one that was refused reads as having taken it. Closing
     * ends that, and the same client opens a new connection for its next
     * command. Whatever the server had not run yet on the old one is either
     * run late or dropped, so a lock whose command failed may stay until its
     * TTL runs out. The 6.4 release candidate closes such a connection
     * itself.
     *
     * What is closed when is in leftBehind(). Relay throws an exception class
     * of its own and is left as it is.
     *
     * @template T
     *
     * @param \Closure(): T $command
     *
     * @return T
     */
    public static function call(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, \Closure $command, ?LoggerInterface $logger = null, bool $raw = false): mixed
    {
        $startedAt = microtime(true);

        try {
            return $command();
        } catch (\RedisException|\RedisClusterException $e) {
            if (self::leftBehind($redis, $e, $raw, microtime(true) - $startedAt)) {
                self::close($redis, $logger);
            }

            throw $e;
        }
    }

    /**
     * Whether phpredis threw an error the server answered with. It throws
     * such a reply, OOM or READONLY say, with the reply as the message and
     * keeps the same text as the client's last error. A broken read leaves
     * the last error alone, so an error an earlier command left there never
     * matches its message.
     */
    public static function answered(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, \Throwable $e): bool
    {
        $message = $e->getMessage();

        if ('' === $message || (!$e instanceof \RedisException && !$e instanceof \RedisClusterException)) {
            return false;
        }

        foreach (self::connections($redis) as $connection) {
            if ($message === $connection->getLastError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether phpredis failed while reading the answer, so that the command
     * reached the server and may have been run. A plain connection then says
     * "read error on connection to ..." or "socket error on read socket", a
     * cluster "Error processing response from Redis node!". A command that
     * was not sent says something else: "Redis server ... went away" when
     * the connection could not be opened, "Can't communicate with any node
     * in the cluster" when a cluster could not send it. "Connection lost" can
     * come before the write or after it, and does not count either.
     */
    public static function readFailed(\Throwable $e): bool
    {
        $message = $e->getMessage();

        if ($e instanceof \RedisClusterException) {
            return 'Error processing response from Redis node!' === $message;
        }

        return $e instanceof \RedisException
            && (str_starts_with($message, 'read error on connection') || 'socket error on read socket' === $message);
    }

    /**
     * When a pause that begins now should end, after an attempt that failed
     * after $seconds (see PAUSE_S).
     */
    public static function pauseAfter(float $seconds): float
    {
        return microtime(true) + max(self::PAUSE_S, self::PAUSE_TIMES * $seconds);
    }

    /**
     * Whether a phpredis command for $key that failed after $seconds ran into
     * the read timeout, or may have: also when the read timeout cannot be
     * told. Then it reached the server, which may still run it, and the
     * server did not answer in time, so another command now would most
     * likely wait as long.
     */
    private static function timedOut(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, float $seconds): bool
    {
        $node = self::node($redis, $key);

        if (!$node instanceof \Redis && !$node instanceof \RedisCluster) {
            return false;
        }

        $timeout = self::readTimeout($node);

        return null === $timeout || $seconds >= self::TIMED_OUT * $timeout;
    }

    /**
     * Runs $command, which sends commands for $key through $redis, waiting at
     * most $seconds for each answer, so that a server that does not answer
     * costs that long and no more. Connecting is not shortened: a connection
     * that has to be opened first takes up to the client's connect timeout.
     *
     * A plain connection gets the shorter read timeout for $command, open or
     * closed, and its own back afterwards. A cluster's node connections keep
     * the read timeout they were opened with, so there $command runs only
     * when that is no longer than $seconds. Relay is left as it is.
     *
     * @param \Closure(): mixed $command
     */
    public static function briefly(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, float $seconds, \Closure $command): void
    {
        $node = self::node($redis, $key);

        if ($node instanceof \RedisCluster) {
            $timeout = self::readTimeout($node);

            if (null !== $timeout && $timeout <= $seconds) {
                $command();
            }

            return;
        }

        if (!$node instanceof \Redis) {
            return;
        }

        $own = $node->getOption(\Redis::OPT_READ_TIMEOUT);
        $timeout = self::readTimeout($node);
        $node->setOption(\Redis::OPT_READ_TIMEOUT, null === $timeout ? $seconds : min($timeout, $seconds));

        try {
            $command();
        } finally {
            // phpredis passes a read timeout of 0, the usual one, to an open
            // connection as "do not wait at all" rather than as PHP's
            // default_socket_timeout, so that one goes back while the
            // connection is closed; the next command opens it again
            if (!is_numeric($own) || 0.0 === (float) $own) {
                self::closeQuietly($node);
            }

            $node->setOption(\Redis::OPT_READ_TIMEOUT, $own);
        }
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
     * @param list<string> $forms           'DELEX', 'DELIFEQ' or 'SET IFEQ'
     * @param list<string> $scriptArguments
     */
    private static function conditional(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $family, array $forms, string $token, int $ttlMs, string $script, array $scriptArguments, ?LoggerInterface $logger): mixed
    {
        // start after the forms this connection has already refused
        $id = spl_object_id($redis);
        $refused = self::$refused[$family][$id] ?? 0;
        $redirected = false;

        for ($i = $refused; $i < \count($forms); ++$i) {
            [$spoken, $reply] = self::attempt($redis, $key, $forms[$i], $token, $ttlMs, $logger);

            if (true === $spoken) {
                return $reply;
            }

            if (null === $spoken) {
                // the cluster sent it to another node: the script follows
                // there (see attempt()), and nothing was refused
                $redirected = true;

                break;
            }

            // refused: the next call starts with the form after this one
            self::$refused[$family][$id] = $i + 1;
        }

        // every native form was refused: use the script
        $node = self::node($redis, $key);

        if (null === $node) {
            return false;
        }

        $startedAt = microtime(true);

        try {
            return self::call($redis, static fn (): mixed => $node->eval($script, [$key, ...$scriptArguments], 1), $logger);
        } catch (\RedisException|\RedisClusterException $e) {
            // a redirect and a node that is down look the same to a raw
            // command; when the script fails too, it was the node
            if ($redirected) {
                self::$detoursPausedUntil = self::pauseAfter(microtime(true) - $startedAt);
            }

            throw $e;
        }
    }

    /**
     * Sends one native form. Returns [true, the reply] when the server
     * accepted it, [false, null] when the server did not know the command or
     * the option, and [null, null] when a cluster redirected it.
     *
     * @return array{bool|null, mixed}
     */
    private static function attempt(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $form, string $token, int $ttlMs, ?LoggerInterface $logger): array
    {
        $node = self::node($redis, $key);

        // no node owns the key: answer false, as a failed command would,
        // without trying the next form
        if (null === $node) {
            return [true, false];
        }

        // a cluster command sent through phpredis's own method, or a script,
        // tries every node before it gives up on one that is down, which
        // takes up to the cluster's timeout; a raw one fails at once. So
        // while a node is down, only the raw form goes out
        $detours = !$redis instanceof \RedisCluster || microtime(true) >= self::$detoursPausedUntil;
        $startedAt = microtime(true);
        $typed = $detours ? self::typed($redis, $key, $form, $token, $ttlMs) : null;

        try {
            $reply = self::call($redis, $typed ?? static fn (): mixed => self::rawForm($redis, $key, $form, $token, $ttlMs), $logger, null === $typed);
        } catch (\Exception $e) {
            // Relay throws on a refusal; any other error is a real failure
            if (self::isRefusal($e->getMessage())) {
                return [false, null];
            }

            // phpredis does not follow a cluster's redirect for a raw command
            // and throws instead: the key's slot has moved, or is moving. The
            // script goes through EVAL, which follows it. Both forms act only
            // while the lock holds our token, so running the script after a
            // native form that may have run changes nothing. Not after a read
            // timeout, though: that server did not answer, and the script
            // would wait as long again. Nor while the detours are paused. A
            // form sent through phpredis's own method has followed any
            // redirect itself: its failure means a node is down, and pauses
            // the detours
            if ($e instanceof \RedisClusterException) {
                if (null !== $typed) {
                    self::$detoursPausedUntil = self::pauseAfter(microtime(true) - $startedAt);
                } elseif ($detours && !self::timedOut($redis, $key, microtime(true) - $startedAt)) {
                    return [null, null];
                }
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
     * One native form sent through phpredis's own method for it, where the
     * client has one; null where it has not, and the form goes raw. A
     * cluster follows a redirect for its own methods, which it does not for
     * a raw command, and the methods prefix the key as SET NX did when it
     * took the lock.
     *
     * The methods are DELEX's from phpredis 6.4, DELIFEQ's from 6.3, and SET
     * with an IFEQ option from 6.2. An older SET would drop the option it
     * does not know and overwrite the lock whatever it holds, so SET IFEQ is
     * sent raw there.
     *
     * @return (\Closure(): mixed)|null
     */
    private static function typed(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $form, string $token, int $ttlMs): ?\Closure
    {
        $node = self::node($redis, $key);

        if (!$node instanceof \Redis && !$node instanceof \RedisCluster) {
            return null;
        }

        return match (true) {
            'DELEX' === $form && self::owns($node, 'delex') => static fn (): mixed => $node->delex($key, ['IFEQ' => $token]),
            'DELIFEQ' === $form && self::owns($node, 'delifeq') => static fn (): mixed => $node->delifeq($key, $token),
            'SET IFEQ' === $form && self::owns($node, 'set') && version_compare((string) phpversion('redis'), '6.2.0', '>=') => static fn (): mixed => $node->set($key, $token, ['PX' => $ttlMs, 'IFEQ' => $token]),
            default => null,
        };
    }

    /**
     * One native form sent as a raw command.
     */
    private static function rawForm(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, string $key, string $form, string $token, int $ttlMs): mixed
    {
        return match ($form) {
            'DELEX' => self::raw($redis, $key, 'DELEX', 'IFEQ', $token),
            'DELIFEQ' => self::raw($redis, $key, 'DELIFEQ', $token),
            default => self::raw($redis, $key, 'SET', $token, 'PX', (string) $ttlMs, 'IFEQ', $token),
        };
    }

    /**
     * Whether $node answers phpredis's method $name itself: phpredis has it,
     * and the object is phpredis's own class or one that declares the method
     * again. Symfony's lazy connection extends \Redis but passes on only the
     * methods it was written for; one it does not know, such as phpredis
     * 6.4's delex(), runs on the unconnected proxy and fails.
     */
    private static function owns(\Redis|\RedisCluster $node, string $name): bool
    {
        $class = $node instanceof \RedisCluster ? \RedisCluster::class : \Redis::class;

        if (!method_exists($class, $name)) {
            return false;
        }

        return $class === $node::class || !(new \ReflectionMethod($node, $name))->getDeclaringClass()->isInternal();
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

    /**
     * The connections behind $redis: a RedisArray's nodes, or $redis itself.
     *
     * @return list<\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster>
     */
    private static function connections(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis): array
    {
        if (!$redis instanceof \RedisArray) {
            return [$redis];
        }

        $nodes = [];
        $hosts = $redis->_hosts();

        foreach (\is_array($hosts) ? $hosts : [] as $host) {
            $node = \is_string($host) ? $redis->_instance($host) : null;

            if ($node instanceof \Redis) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Whether a failed command may have left an answer on the connection.
     *
     * Not when the server answered with an error (see answered()). On a
     * cluster, phpredis disconnects a node itself when a command sent through
     * one of its methods fails while reading the answer, so there only a
     * reply cut short, which phpredis throws as a RedisException, or a raw
     * command can leave one; and a raw command only when it waited out the
     * read timeout, or may have. One that failed sooner was redirected or
     * refused, and that answer was read in full. phpredis 6.3 can leave one
     * in three more places, which the 6.4 release candidate closes itself and
     * which are left as they are here: an ASKING or READONLY it sends ahead
     * of a command, and a command sent to one node, such as SCRIPT LOAD.
     */
    private static function leftBehind(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, \Exception $e, bool $raw, float $seconds): bool
    {
        if (self::answered($redis, $e)) {
            return false;
        }

        if (!$redis instanceof \RedisCluster || $e instanceof \RedisException) {
            return true;
        }

        return $raw && self::timedOut($redis, '', $seconds);
    }

    /**
     * Closes the connection; on a RedisArray, the one to every node, since
     * the failure may have come from any of them.
     */
    private static function close(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, ?LoggerInterface $logger): void
    {
        foreach (self::connections($redis) as $connection) {
            self::closeQuietly($connection);
        }

        if ($redis instanceof \RedisCluster) {
            self::warnAboutReplicas($redis, $logger);
        }
    }

    private static function closeQuietly(\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $connection): void
    {
        try {
            // a plain connection that is already closed would connect again
            // only to close: phpredis's close() opens it first
            if (!$connection instanceof \Redis || $connection->isConnected()) {
                $connection->close();
            }
        } catch (\Throwable) {
            // nothing left to close
        }
    }

    /**
     * The read timeout of a phpredis connection, in seconds, INF when reads
     * never time out, null when it cannot be told. The option says 0 when
     * PHP's default_socket_timeout applies. A cluster's node connections keep
     * the timeout they were opened with, which the option stops showing once
     * it is changed, so there the shorter of the option and PHP's default
     * counts. An option raised after they were opened is then too long, and
     * nothing outside phpredis can tell.
     */
    private static function readTimeout(\Redis|\RedisCluster $node): ?float
    {
        $option = $node->getOption(\Redis::OPT_READ_TIMEOUT);

        if (!is_numeric($option)) {
            return null;
        }

        $option = (float) $option;
        $default = (float) \ini_get('default_socket_timeout');
        $default = $default > 0 ? $default : \INF;

        if ($option < 0) {
            $option = \INF;
        } elseif (0.0 === $option) {
            $option = $default;
        }

        return $node instanceof \RedisCluster ? min($option, $default) : $option;
    }

    /**
     * phpredis before 6.4 fails every read a closed cluster connection sends
     * to a replica: the reads throw, they do not answer wrongly. Said once per
     * connection set to read from replicas, where a logger is at hand.
     */
    private static function warnAboutReplicas(\RedisCluster $cluster, ?LoggerInterface $logger): void
    {
        $version = (string) phpversion('redis');
        $warned = self::$warnedAboutReplicas ??= new \WeakMap();

        if (null === $logger
            || isset($warned[$cluster])
            || !version_compare($version, '6.4.0-dev', '<')
            || \RedisCluster::FAILOVER_NONE === $cluster->getOption(\RedisCluster::OPT_SLAVE_FAILOVER)
        ) {
            return;
        }

        $warned[$cluster] = true;
        $logger->warning('A Redis Cluster connection set to read from replicas was closed after a failed command. With phpredis {version}, every read it sends to a replica fails from now on.', ['version' => $version]);
    }
}

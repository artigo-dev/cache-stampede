<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A connection to the test server that plays phpredis before 6.4 after a
 * read timeout, without one: the armed command runs, its caller is told the
 * read failed, and from then on every command is answered with the reply of
 * the one sent before it, until close(). That is what a reply arriving late
 * on a connection that stayed open does.
 *
 * A command can also be made to fail with an error the server answered
 * with, or before it is sent, as when the connection cannot be opened: then
 * nothing is left behind, as with phpredis.
 *
 * It also counts its close() calls, remembers what it published, the read
 * timeout each command went out with and the read timeouts it was asked to
 * set, and can refuse the native conditional forms the way a server without
 * them does. A read timeout it is asked to set is remembered, not applied:
 * tests must not hang on a real reconnect within a real 100 ms.
 */
final class ShiftingRedis extends \Redis
{
    public int $closed = 0;

    /** @var list<string> */
    public array $published = [];

    /** @var list<array{string, float}> each command, and its read timeout */
    public array $sent = [];

    /** @var list<string> "DELEX", "DELIFEQ" */
    public array $refuse = [];

    /** @var list<float> the read timeouts setOption() was asked for */
    public array $readTimeouts = [];

    /** @var list<array{list<string>, float, ?string, bool}> the failures to come, in order */
    private array $armed = [];

    private bool $shifted = false;

    /** @var list<mixed> */
    private array $late = [];

    private ?string $refusal = null;

    public static function fromDsn(float $readTimeout = 2.0): self
    {
        $url = parse_url(Connection::dsn());
        $host = \is_array($url) && \is_string($url['host'] ?? null) ? $url['host'] : '127.0.0.1';
        $port = \is_array($url) && \is_int($url['port'] ?? null) ? $url['port'] : 6379;
        $redis = new self();

        try {
            $redis->connect($host, $port, 1.0, null, 0, $readTimeout);
        } catch (\Throwable $e) {
            TestCase::markTestSkipped(\sprintf('Redis is not reachable at "%s": %s', Connection::dsn(), $e->getMessage()));
        }

        return $redis;
    }

    /**
     * The next call of one of $methods runs, then throws as a read that timed
     * out after $stall seconds would. With $answer it does not run, and
     * throws that error as one the server answered with; with $sent false it
     * does not run either, and throws as a connection that could not be
     * opened. Several calls queue up, one failure each.
     *
     * @param list<string> $methods
     */
    public function failNext(array $methods, float $stall = 0.0, ?string $answer = null, bool $sent = true): void
    {
        $this->armed[] = [$methods, $stall, $answer, $sent];
    }

    public function setOption(int $option, mixed $value): bool
    {
        if (\Redis::OPT_READ_TIMEOUT === $option && is_numeric($value)) {
            $this->readTimeouts[] = (float) $value;

            return true;
        }

        return parent::setOption($option, $value);
    }

    /**
     * Whether every failure armed with failNext() has been played.
     */
    public function fired(): bool
    {
        return [] === $this->armed;
    }

    /**
     * How many commands of this name were sent.
     */
    public function count(string $method): int
    {
        return \count(array_filter($this->sent, static fn (array $sent): bool => $method === $sent[0]));
    }

    /**
     * @param array<mixed>|null $options
     */
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
    {
        $reply = $this->through('set', fn (): mixed => null === $options ? parent::set($key, $value) : parent::set($key, $value, $options));

        return $reply instanceof \Redis || \is_bool($reply) || \is_string($reply) ? $reply : false;
    }

    public function pttl(string $key): \Redis|int|false
    {
        $reply = $this->through('pttl', fn (): mixed => parent::pttl($key));

        return $reply instanceof \Redis || \is_int($reply) ? $reply : false;
    }

    /**
     * @param array<string>|string $key
     * @param string               ...$other_keys
     */
    public function exists(mixed $key, mixed ...$other_keys): \Redis|int|bool
    {
        $reply = $this->through('exists', fn (): mixed => parent::exists($key, ...$other_keys));

        return $reply instanceof \Redis || \is_int($reply) || \is_bool($reply) ? $reply : false;
    }

    public function publish(string $channel, string $message): \Redis|int|false
    {
        $this->published[] = $channel;
        $reply = $this->through('publish', fn (): mixed => parent::publish($channel, $message));

        return $reply instanceof \Redis || \is_int($reply) ? $reply : false;
    }

    public function rawCommand(string $command, mixed ...$args): mixed
    {
        if (\in_array($command, $this->refuse, true)) {
            $this->refusal = \sprintf("ERR unknown command '%s', with args beginning with: ...", $command);

            return false;
        }

        return $this->through('rawCommand', fn (): mixed => parent::rawCommand($command, ...$args));
    }

    /**
     * @param array<mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        return $this->through('eval', fn (): mixed => parent::eval($script, $args, $num_keys));
    }

    /**
     * @param array<mixed> $args
     */
    public function evalSha(string $sha1, array $args = [], int $num_keys = 0): mixed
    {
        return $this->through('evalSha', fn (): mixed => parent::evalSha($sha1, $args, $num_keys));
    }

    public function getLastError(): ?string
    {
        return $this->refusal ?? parent::getLastError();
    }

    public function clearLastError(): bool
    {
        $this->refusal = null;

        return parent::clearLastError();
    }

    public function close(): bool
    {
        ++$this->closed;
        $this->shifted = false;
        $this->late = [];

        return parent::close();
    }

    /**
     * Sends the command, and answers as described above.
     *
     * @param \Closure(): mixed $command
     */
    private function through(string $method, \Closure $command): mixed
    {
        $timeout = $this->getOption(\Redis::OPT_READ_TIMEOUT);
        $this->sent[] = [$method, is_numeric($timeout) ? (float) $timeout : 0.0];
        [$methods, $stall, $answer, $sent] = $this->armed[0] ?? [[], 0.0, null, true];

        if (\in_array($method, $methods, true)) {
            array_shift($this->armed);

            if (null !== $answer) {
                $this->refusal = $answer;

                throw new \RedisException($answer);
            }

            if (!$sent) {
                usleep((int) ($stall * 1_000_000));

                throw new \RedisException(\sprintf('Redis server %s:%d went away', $this->getHost(), $this->getPort()));
            }

            $reply = $command();
            $this->shifted = true;
            $this->late = [$reply];
            usleep((int) ($stall * 1_000_000));

            // what phpredis says when the answer does not come: a command of
            // its own reads a line first, a raw command or a script a type
            if (\in_array($method, ['rawCommand', 'eval', 'evalSha'], true)) {
                throw new \RedisException('socket error on read socket');
            }

            throw new \RedisException(\sprintf('read error on connection to %s:%d', $this->getHost(), $this->getPort()));
        }

        $reply = $command();

        if (!$this->shifted) {
            return $reply;
        }

        $this->late[] = $reply;

        return self::readAs($method, array_shift($this->late));
    }

    /**
     * How phpredis reads a reply that was meant for another command: SET
     * takes only an OK as true, the integer commands only an integer, and
     * the rest whatever came.
     */
    private static function readAs(string $method, mixed $reply): mixed
    {
        return match ($method) {
            'set' => true === $reply,
            'pttl', 'exists', 'publish' => \is_int($reply) ? $reply : false,
            default => $reply,
        };
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\Stale\Record;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * A value kept for errors, read by several processes at once while its
 * computation fails: real processes, a real MemoLock on the inner pool. One
 * of them computes and fails; the others get the kept value when it has
 * failed, and none of them computes again.
 */
final class StaleIfErrorFleetTest extends TestCase
{
    private const PROCESSES = 6;
    private const COMPUTATION_MS = 300;

    private \Redis|\Relay\Relay $redis;

    private string $namespace;

    protected function setUp(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is needed to start the requests.');
        }

        $this->redis = Connection::openSingleNode();
        $this->namespace = 'stale-fleet'.bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            Connection::sweep($this->redis, $this->namespace.'*');
            Connection::sweep($this->redis, 'memolock:*');
        }
    }

    public function testRequestsBehindAFailingComputationGetTheKeptValueWithoutRepeatingIt(): void
    {
        // past its stale time, kept for errors
        $inner = new RedisAdapter($this->redis, $this->namespace);
        $inner->save($inner->getItem('report')->set(new Record('last good', microtime(true) - 70, 60, 600))->expiresAfter(600));

        // far enough ahead for every process to have started
        $startAt = microtime(true) + 1.5;
        $processes = [];

        for ($i = 0; $i < self::PROCESSES; ++$i) {
            $process = proc_open(
                [\PHP_BINARY, __DIR__.'/Fixtures/kept-reader.php', $this->namespace, \sprintf('%.6F', $startAt), (string) self::COMPUTATION_MS],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['REDIS_DSN' => Connection::dsn()] + getenv(),
            );
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        $slowest = 0;

        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $answer = json_decode((string) $output, true);
            self::assertIsArray($answer, 'a request failed: '.$output.$errors);
            self::assertSame('last good', $answer['value'] ?? null);
            self::assertIsInt($answer['ms'] ?? null);
            $slowest = max($slowest, $answer['ms']);
        }

        self::assertSame('1', $this->redis->get($this->namespace.':computations'), 'one process computed and failed; nobody repeated it');
        self::assertLessThan(2 * self::COMPUTATION_MS, $slowest, 'and nobody waited for more than that one computation');
    }
}

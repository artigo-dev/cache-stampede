<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Eight requests arrive on a cache key that has just expired.
 *
 * Without a lock all eight run the query. With Symfony's own, one per machine
 * runs it - which is one, here, because this example is one machine. With this
 * one it is one however many machines there are, and the seven that did not
 * run it are served the moment the one that did is finished.
 *
 *   docker compose up -d
 *   php examples/expensive-query.php
 *
 * Runs against REDIS_DSN, or redis://127.0.0.1:6379 by default.
 */

use Artigo\Cache\MemoLock;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\ItemInterface;

require __DIR__.'/../vendor/autoload.php';

$dsn = getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379';
$queryMs = 400;
$counter = 'example:queries-run';

// ---------------------------------------------------------------- the worker

if (($argv[1] ?? '') === 'request') {
    $startAt = (float) $argv[2];
    $redis = RedisAdapter::createConnection($dsn);

    $pool = new RedisAdapter($redis, 'example');

    // The whole integration. setCallbackWrapper() is public on every Symfony
    // adapter, so this works on the pool you already have.
    $pool->setCallbackWrapper(MemoLock::fromDsn($dsn, waitTimeoutMs: 5_000));

    while (microtime(true) < $startAt) {
        usleep(200);
    }

    $began = microtime(true);
    $ran = false;

    $report = $pool->get('monthly-report', static function (ItemInterface $item) use ($redis, $counter, $queryMs, &$ran): string {
        $ran = true;
        $item->expiresAfter(60);

        // whatever is expensive: the query, the API call, the render
        $redis->incr($counter);
        usleep($queryMs * 1000);

        return 'the report';
    });

    printf("  %-8s after %5.0f ms  ->  %s\n", $ran ? 'queried' : 'waited', (microtime(true) - $began) * 1000, $report);

    exit(0);
}

// ---------------------------------------------------------------- the parent

$redis = RedisAdapter::createConnection($dsn);
(new RedisAdapter($redis, 'example'))->clear();
$redis->del($counter);

printf("eight requests on a cold key, %d ms to answer it\n\n", $queryMs);

$startAt = microtime(true) + 1.0;
$processes = [];
$streams = [];

for ($i = 0; $i < 8; ++$i) {
    $processes[] = proc_open([\PHP_BINARY, __FILE__, 'request', (string) $startAt], [1 => ['pipe', 'w']], $pipes);
    $streams[] = $pipes[1];
}

foreach ($processes as $index => $process) {
    echo stream_get_contents($streams[$index]);
    fclose($streams[$index]);
    proc_close($process);
}

printf("\nthe query ran %d time(s) for 8 requests\n", (int) $redis->get($counter));

(new RedisAdapter($redis, 'example'))->clear();
$redis->del($counter);

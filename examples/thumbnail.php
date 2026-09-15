<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Four requests ask for the same thumbnail at the same moment.
 *
 * One of them renders it. The other three wait - not for the lock, for the
 * *file* - and return it the moment it exists. That is the difference between
 * once() and a mutex: a mutex would hand the lock to each of them in turn and
 * let each decide, again, whether to render.
 *
 *   docker compose up -d
 *   php examples/thumbnail.php
 *
 * Runs against REDIS_DSN, or redis://127.0.0.1:6379 by default.
 */

use Artigo\Cache\MemoLock;

require __DIR__.'/../vendor/autoload.php';

$dsn = getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379';
$target = sys_get_temp_dir().'/artigo-thumbnail-example.txt';
$renderMs = 400;

// ---------------------------------------------------------------- the worker

if (($argv[1] ?? '') === 'request') {
    $startAt = (float) $argv[2];
    $lock = MemoLock::fromDsn($dsn, waitTimeoutMs: 5_000);

    while (microtime(true) < $startAt) {
        usleep(200);
    }

    $began = microtime(true);
    $rendered = false;

    $thumbnail = $lock->once(
        'thumbnail:example',
        // Is it there yet? null means "not yet" - and this is asked before any
        // lock is taken, so a request arriving after the work never takes one.
        exists: static function () use ($target): ?string {
            clearstatcache(true, $target);

            return is_file($target) ? $target : null;
        },
        // Whatever is expensive. Only one of the four ever gets here.
        make: static function () use ($target, $renderMs, &$rendered): string {
            $rendered = true;
            usleep($renderMs * 1000);
            file_put_contents($target, 'a thumbnail, rendered at '.microtime(true));

            return $target;
        },
    );

    printf("  %-8s after %5.0f ms  ->  %s\n",
        $rendered ? 'rendered' : 'waited',
        (microtime(true) - $began) * 1000,
        basename($thumbnail),
    );

    exit(0);
}

// ---------------------------------------------------------------- the parent

@unlink($target);

printf("four requests for one thumbnail, %d ms to render\n\n", $renderMs);

$startAt = microtime(true) + 1.0;
$processes = [];

for ($i = 0; $i < 4; ++$i) {
    $processes[] = proc_open(
        [\PHP_BINARY, __FILE__, 'request', (string) $startAt],
        [1 => ['pipe', 'w']],
        $pipes,
    );
    $streams[] = $pipes[1];
}

foreach ($processes as $index => $process) {
    echo stream_get_contents($streams[$index]);
    fclose($streams[$index]);
    proc_close($process);
}

printf("\none render, four answers. A second run finds the file already there\n");
printf("and none of them takes a lock at all - delete %s to start over.\n", basename($target));

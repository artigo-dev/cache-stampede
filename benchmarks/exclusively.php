<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * What does handing a critical section over cost?
 *
 * Spawns N real processes, releases them on a shared wall-clock barrier, and
 * lets each hold the same critical section for sectionMs, one after the
 * other. The sections never overlap, so the total is N x sectionMs plus what
 * the handovers cost: with symfony/lock on RedisStore, the next worker's poll;
 * with MemoLock::exclusively(), a message.
 *
 *   php benchmarks/exclusively.php mode=symfony
 *   php benchmarks/exclusively.php mode=memolock
 *
 * Options: dsn=, mode=, workers=, sectionMs=
 */

use Artigo\Cache\MemoLock;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

require __DIR__.'/../vendor/autoload.php';

const MODES = ['symfony', 'memolock'];

// ---------------------------------------------------------------- the worker

if ('worker' === ($argv[1] ?? '')) {
    [, , $dsn, $mode, $key, $startAt, $sectionMs] = $argv;
    $startAt = (float) $startAt;
    $sectionMs = (int) $sectionMs;

    // the section itself: when it was entered and when it was left
    $section = static function () use ($sectionMs): array {
        $entered = microtime(true);
        usleep($sectionMs * 1000);

        return [$entered, microtime(true)];
    };

    // everything is built before the barrier, so only the waiting is measured
    if ('symfony' === $mode) {
        $lock = (new LockFactory(new RedisStore(RedisAdapter::createConnection($dsn))))->createLock($key, 30.0);
    } else {
        $memoLock = MemoLock::fromDsn($dsn);
    }

    // the barrier: everybody leaves at the same instant
    while (microtime(true) < $startAt) {
        usleep(200);
    }

    if ('symfony' === $mode) {
        // a blocking acquire on a store that cannot block: the Lock component
        // tries again every 100 ms or so
        $lock->acquire(true);

        try {
            [$entered, $left] = $section();
        } finally {
            $lock->release();
        }
    } else {
        [$entered, $left] = $memoLock->exclusively($key, static fn (): array => $section());
    }

    echo json_encode(['entered' => $entered, 'left' => $left, 'done' => microtime(true)], \JSON_THROW_ON_ERROR), \PHP_EOL;

    exit(0);
}

// ---------------------------------------------------------------- the parent

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_contains($argument, '=')) {
        [$name, $value] = explode('=', $argument, 2);
        $options[$name] = $value;
    }
}

$dsn = (string) ($options['dsn'] ?? getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379');
$mode = (string) ($options['mode'] ?? 'memolock');
$workers = (int) ($options['workers'] ?? 8);
$sectionMs = (int) ($options['sectionMs'] ?? 200);

if (!in_array($mode, MODES, true)) {
    fwrite(\STDERR, sprintf('Unknown mode "%s"; use one of %s.%s', $mode, implode(', ', MODES), \PHP_EOL));

    exit(1);
}

$key = 'import:companies:'.bin2hex(random_bytes(4));
$startAt = microtime(true) + 1.5;
$processes = [];

for ($index = 0; $index < $workers; ++$index) {
    $process = proc_open(
        [\PHP_BINARY, __FILE__, 'worker', $dsn, $mode, $key, (string) $startAt, (string) $sectionMs],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a worker.');
    }

    $processes[$index] = [$process, $pipes];
}

$reports = [];

foreach ($processes as $index => [$process, $pipes]) {
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $report = json_decode(trim((string) $out), true);

    if (!is_array($report)) {
        fwrite(\STDERR, sprintf('Worker %d: %s%s', $index, trim($err ?: 'the worker said nothing'), \PHP_EOL));

        exit(1);
    }

    $reports[] = $report;
}

// in the order they held the section, which must never overlap
usort($reports, static fn (array $a, array $b): int => $a['entered'] <=> $b['entered']);

for ($index = 1; $index < count($reports); ++$index) {
    if ($reports[$index]['entered'] < $reports[$index - 1]['left']) {
        fwrite(\STDERR, 'Two workers held the section at once.'.\PHP_EOL);

        exit(1);
    }
}

$totalMs = (int) round((max(array_column($reports, 'done')) - $startAt) * 1000);

echo sprintf('%s | %d workers | a %d ms section each | %s%s',
    $mode, $workers, $sectionMs, $dsn, \PHP_EOL);
echo sprintf('  total %d ms, handovers %d ms over %d workers%s',
    $totalMs, $totalMs - $workers * $sectionMs, $workers, \PHP_EOL);

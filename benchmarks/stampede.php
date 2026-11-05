<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * How many requests reach the origin when a hot key goes cold?
 *
 * Spawns N real processes, releases them on a shared wall-clock barrier
 * against one cold key, and counts how many of them run the expensive
 * callback. One is the answer a cache is supposed to give. N is what no
 * protection looks like.
 *
 * The interesting column is what happens to Symfony's LockRegistry as the
 * fleet grows: it flock()s *local* files, so its scope is one machine. A host
 * is, to flock(), nothing more than its own set of lock files - which is what
 * lets this model a 12-server fleet on one laptop.
 *
 *   php benchmarks/stampede.php
 *   php benchmarks/stampede.php workers=12 resolverMs=300 hosts=12
 *   php benchmarks/stampede.php modes=memolock,symfony-multihost
 *   php benchmarks/stampede.php modes=swr
 *
 * Options: dsn=, workers=, resolverMs=, hosts=, lead=, modes=, keys=
 * Modes: nolock, memolock, fleetlock (symfony/lock's RedisStore), fleetlock-pubsub
 * (the same on a RedisStore whose waiters are woken by a message),
 * symfony-onehost, symfony-multihost
 *
 * modes=swr asks another question: what does a visitor wait when a hot key
 * turns stale? Its three rows share one warm key, written beforehand:
 *
 *   expiring   MemoLock, the key expiring at the barrier: one computes, the
 *              rest wait for it
 *   beta       MemoLock with Symfony's probabilistic early expiration, the
 *              barrier 100 ms before the key expires: some recompute early,
 *              behind the lock, and wait for it
 *   swr        StaleCache over the same pool, the key's lifetime ending at the
 *              barrier: every visitor is served the stale value at once, and
 *              one refreshes it after its response
 *
 * keys=collide gives every worker a *different* cold key, chosen so they all
 * land on the same LockRegistry slot. LockRegistry spreads the whole keyspace
 * over a couple of dozen lock files - 24 on Symfony 8.1, 25 on 6.4 and 7.3 -
 * so unrelated items share a lock and wait for one another. This models it
 * with a set of its own, LOCK_FILES wide; the count changes which keys happen
 * to collide and nothing else.
 */

use Artigo\Cache\StaleCache;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\ItemInterface;

require __DIR__.'/../vendor/autoload.php';

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_contains($argument, '=')) {
        [$name, $value] = explode('=', $argument, 2);
        $options[$name] = $value;
    }
}

$dsn = (string) ($options['dsn'] ?? getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379');
$workers = (int) ($options['workers'] ?? 12);
$resolverMs = (int) ($options['resolverMs'] ?? 300);
$hosts = (int) ($options['hosts'] ?? 3);
$lead = (float) ($options['lead'] ?? 1.5);
$modes = explode(',', (string) ($options['modes'] ?? 'nolock,memolock,symfony-onehost,symfony-multihost'));

// the stale-while-revalidate question, against what it replaces
if (['swr'] === $modes) {
    $modes = ['expiring', 'beta', 'swr'];
}

/**
 * The modes whose key is warm before the barrier, and what a visitor waits
 * is the question.
 */
const WARM_MODES = ['expiring', 'beta', 'swr'];

/**
 * How long before its expiry the beta row's visitors arrive.
 */
const BETA_LEAD = 0.1;

/**
 * How long the warm key lives before the barrier.
 */
const WARM_SECONDS = 2;
$colliding = 'collide' === ($options['keys'] ?? '');

if ('\\' === \DIRECTORY_SEPARATOR) {
    fwrite(\STDERR, <<<'TEXT'
        ! Windows: Symfony disables LockRegistry here outright, so its rows will
        ! read 0 protection for a reason that has nothing to do with fleet size.
        ! Run this under Linux (docker compose up -d) for a fair comparison.

        TEXT);
}

/**
 * How many lock files LockRegistry is given here. Symfony's own default sits in
 * the same range and has moved within it; what matters is that a fixed, small
 * number of locks covers an unbounded keyspace.
 */
const LOCK_FILES = 25;

$redis = RedisAdapter::createConnection($dsn);
$run = bin2hex(random_bytes(4));
$lockFiles = lockFiles($hosts);
$results = [];

echo sprintf('%s | %d workers | origin takes %d ms | %d simulated hosts%s%s',
    $dsn, $workers, $resolverMs, $hosts, \PHP_EOL, \PHP_EOL);

foreach ($modes as $mode) {
    $mode = trim($mode);

    if ('' === $mode) {
        continue;
    }

    $key = sprintf('cold-%s-%s', $mode, $run);
    $keys = $colliding ? collidingKeys($workers, $mode.$run) : [];
    $counterKey = sprintf('stampede:origin:%s:%s', $mode, $run);
    $redis->del($counterKey);

    $startAt = microtime(true) + $lead;
    $processes = [];

    if (in_array($mode, WARM_MODES, true)) {
        $startAt = warm($redis, $mode, $key, $resolverMs);
    }

    for ($index = 0; $index < $workers; ++$index) {
        // onehost puts every worker on the same lock files; multihost deals
        // them out, which is exactly what separate machines look like
        $host = 'symfony-multihost' === $mode ? $index % max(1, $hosts) : 0;

        $processes[$index] = spawn([
            'dsn' => $dsn,
            'mode' => $mode,
            'key' => $keys[$index] ?? $key,
            'counterKey' => $counterKey,
            'startAt' => $startAt,
            'resolverMs' => $resolverMs,
            'index' => $index,
            'host' => $host,
            'lockFiles' => $lockFiles[$host],
        ]);
    }

    $reports = [];

    foreach ($processes as $index => $process) {
        $reports[$index] = collect($process, $index);
    }

    $origins = (int) $redis->get($counterKey);
    $redis->del($counterKey);
    (new RedisAdapter($redis, 'stampede-'.$mode))->clear();

    $late = count(array_filter($reports, static fn (array $report): bool => (bool) ($report['late'] ?? false)));
    $errors = array_filter(array_column($reports, 'error'));
    $slowest = max(array_map(static fn (array $report): float => (float) ($report['seconds'] ?? 0), $reports));

    $waits = array_map(static fn (array $report): float => (float) ($report['seconds'] ?? 0), $reports);
    sort($waits);

    $results[$mode] = [
        'origins' => $origins,
        'slowest' => $slowest,
        'median' => $waits[intdiv(count($waits), 2)] ?? 0.0,
        // waited for an origin call, their own or the lock holder's
        'waited' => count(array_filter($waits, static fn (float $wait): bool => $wait > $resolverMs / 2000)),
        'overhead' => $slowest - $resolverMs / 1000,
        'late' => $late,
        'errors' => $errors,
    ];

    echo sprintf('  %-20s %2d/%d origin calls, slowest %.3f s%s%s',
        $mode,
        $origins,
        $workers,
        $slowest,
        $late > 0 ? sprintf(' (%d missed the barrier)', $late) : '',
        \PHP_EOL,
    );

    $spread = array_map(static fn (array $r): string => sprintf('%s%.3f', ($r['origin'] ?? false) ? '*' : '', (float) ($r['seconds'] ?? 0)), $reports);
    echo sprintf('    workers: %s%s', implode(' ', $spread), \PHP_EOL);

    foreach ($errors as $error) {
        echo sprintf('    ! %s%s', $error, \PHP_EOL);
    }
}

echo \PHP_EOL;

$warm = array_intersect_key($results, array_flip(WARM_MODES));

if ([] !== $warm) {
    echo '| Setup | Origin calls | Visitors who waited for one | Visitor wait p50 | Visitor wait max |'.\PHP_EOL;
    echo '|---|---|---:|---:|---:|'.\PHP_EOL;

    foreach ($warm as $mode => $result) {
        echo sprintf('| `%s` | **%d** | %d / %d | %d ms | %d ms |%s',
            $mode,
            $result['origins'],
            $result['waited'],
            $workers,
            (int) round($result['median'] * 1000),
            (int) round($result['slowest'] * 1000),
            \PHP_EOL,
        );
    }

    echo \PHP_EOL;
}

$cold = array_diff_key($results, $warm);

if ([] === $cold) {
    exit(0);
}

echo '| Setup | Origin calls | Slowest worker | Overhead over the origin call |'.\PHP_EOL;
echo '|---|---|---|---|'.\PHP_EOL;

foreach ($cold as $mode => $result) {
    echo sprintf('| `%s` | **%d / %d** | %.3f s | %d ms |%s',
        $mode,
        $result['origins'],
        $workers,
        $result['slowest'],
        (int) round($result['overhead'] * 1000),
        \PHP_EOL,
    );
}

echo \PHP_EOL;

/**
 * Writes the key a warm mode's visitors find, and answers when they arrive:
 * just after its lifetime ends, or, for beta, a little before, the value
 * having taken the origin's time to compute, which is what early expiration
 * weighs.
 *
 * The lifetime ends on a whole second: Symfony keeps the expiry it weighs in
 * whole seconds, and hands Redis a TTL in whole seconds cut down.
 */
function warm(Redis|RedisCluster $redis, string $mode, string $key, int $resolverMs): float
{
    $pool = new RedisAdapter($redis, 'stampede-'.$mode);
    // taken once the value is computed, which takes a while for beta
    $expiry = static fn (): int => (int) ceil(microtime(true)) + WARM_SECONDS;

    if ('swr' === $mode) {
        $stale = new StaleCache($pool, staleSeconds: 60);
        $endsAt = $expiry();
        $stale->save($stale->getItem($key)->set('stale')->expiresAt(new DateTimeImmutable('@'.$endsAt)));
    } else {
        $endsAt = 0;
        $pool->get($key, static function (ItemInterface $item) use ($mode, $resolverMs, $expiry, &$endsAt): string {
            if ('beta' === $mode) {
                usleep($resolverMs * 1000);
            }

            $endsAt = $expiry();

            // the metadata keeps the whole second, the TTL Redis is given is
            // cut down to one: beta's value outlives its barrier that way
            $item->expiresAt('beta' === $mode ? DateTimeImmutable::createFromFormat('U.u', $endsAt.'.850000') ?: null : new DateTimeImmutable('@'.$endsAt));

            return 'stale';
        });
    }

    $expiry = $endsAt;

    return 'beta' === $mode ? $expiry - BETA_LEAD : $expiry + BETA_LEAD;
}

/**
 * Distinct keys that all hash to the same LockRegistry slot, so the lock they
 * share is the only thing they have in common.
 *
 * @return list<string>
 */
function collidingKeys(int $count, string $salt): array
{
    $slots = [];

    for ($i = 0; $i < 100000; ++$i) {
        $candidate = sprintf('collide-%s-%d', $salt, $i);
        $slot = abs(crc32($candidate)) % LOCK_FILES;
        $slots[$slot][] = $candidate;

        if (count($slots[$slot]) >= $count) {
            return $slots[$slot];
        }
    }

    throw new RuntimeException('Could not find enough keys sharing a slot.');
}

/**
 * LockRegistry needs files that already exist, and uses their count as its
 * concurrency limit. One directory per simulated host.
 *
 * @return array<int, list<string>>
 */
function lockFiles(int $hosts): array
{
    $files = [];

    for ($host = 0; $host < max(1, $hosts); ++$host) {
        $directory = sprintf('%s/artigo-stampede/host-%d', sys_get_temp_dir(), $host);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create "%s".', $directory));
        }

        for ($slot = 0; $slot < LOCK_FILES; ++$slot) {
            $file = sprintf('%s/lock-%02d', $directory, $slot);

            if (!is_file($file)) {
                touch($file);
            }

            $files[$host][] = $file;
        }
    }

    return $files;
}

/**
 * @param array<string, mixed> $payload
 *
 * @return array{resource, array<int, resource>}
 */
function spawn(array $payload): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $command = [\PHP_BINARY, __DIR__.'/src/stampede-worker.php', json_encode($payload, \JSON_THROW_ON_ERROR)];
    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a worker.');
    }

    return [$process, $pipes];
}

/**
 * @param array{resource, array<int, resource>} $process
 *
 * @return array<string, mixed>
 */
function collect(array $process, int $index): array
{
    [$handle, $pipes] = $process;

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($handle);

    $report = json_decode(trim((string) $out), true);

    if (!is_array($report)) {
        return ['index' => $index, 'seconds' => 0.0, 'error' => trim($err ?: 'the worker said nothing')];
    }

    return $report;
}

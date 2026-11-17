<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Fixtures;

use Artigo\Cache\MemoLock;
use Artigo\Cache\StaleCache;
use Artigo\Cache\Tests\Connection;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/*
 * One request of StaleIfErrorFleetTest: reads the key "report" of the
 * namespace it is given, through a StaleCache over a RedisAdapter whose lock
 * is a MemoLock, with a computation that counts itself in Redis, takes a
 * while, and throws. Prints what it was served and how long that took.
 *
 *     php kept-reader.php <namespace> <start at, a Unix time> <computation, in milliseconds>
 */

require \dirname(__DIR__, 2).'/vendor/autoload.php';

/**
 * The computation: it counts itself, takes a while, and throws.
 */
function down(\Redis|\Relay\Relay $redis, string $namespace, int $milliseconds): \Closure
{
    return static function () use ($redis, $namespace, $milliseconds): string {
        $redis->incr($namespace.':computations');
        usleep($milliseconds * 1000);

        throw new \RuntimeException('the origin is down');
    };
}

$namespace = (string) ($argv[1] ?? '');
$startAt = (float) ($argv[2] ?? 0);
$milliseconds = (int) ($argv[3] ?? 200);

$redis = Connection::openSingleNode();
$lock = MemoLock::fromDsn(Connection::dsn());
$inner = new RedisAdapter($redis, $namespace);
$inner->setCallbackWrapper($lock);
$pool = new StaleCache($inner, staleSeconds: 60, staleIfErrorSeconds: 600, election: $lock);

// every request of the test reads at the same moment
if (0 < $wait = $startAt - microtime(true)) {
    usleep((int) ($wait * 1_000_000));
}

$began = microtime(true);
$value = $pool->get('report', down($redis, $namespace, $milliseconds));

echo json_encode(['value' => $value, 'ms' => (int) round((microtime(true) - $began) * 1000)]);

# StaleCache: serving stale while it refreshes

[← Back to the README](../README.md)

**The setup.** A lock keeps a hot key from being computed twelve times when it
expires; the other eleven requests still wait for the one computation.
`StaleCache` keeps them from waiting at all: for a grace period past its
lifetime, a value is served at once while one process refreshes it.

**The question.** When a popular value's lifetime ends, does anyone have to
wait for it to be computed again?

**Example.** Twelve requests arrive at a hot key as its lifetime ends, and the
origin takes 300 ms (`stampede.php modes=swr`, `MemoLock` on every row):

| Setup | Origin calls | Requests that waited for one | Wait p50 | Wait max |
|---|---|---:|---:|---:|
| `expiring` | 1 | 12 / 12 | 303 ms | 304 ms |
| `beta` | 1 | 8 / 12 | 302 ms | 303 ms |
| **`swr`** | 1 | **0 / 12** | **1 ms** | **1 ms** |

- **`expiring`**, the key going cold at the barrier: one computes, eleven wait
  for it.
- **`beta`**, Symfony's probabilistic early expiration, with the twelve
  arriving in a burst 100 ms before the expiry, which is its worst case: with
  β = 1 and a 300 ms computation each draws an early recomputation about 72 %
  of the time (8 to 10 of 12 over six runs), and all of those wait behind
  the lock for the one that runs. Requests spread over the last seconds would
  mostly find the value already recomputed.
- **`swr`**, `StaleCache`: every request is served at once and the refresh
  runs after the response; the origin call is the refresh.

So the stale value is served for up to `staleSeconds` after its lifetime,
and in exchange nobody waits for the refresh. Redis 8.10.1, PHP 8.5.12, this
package 2.0.0 over symfony/cache 8.1.11, the benchmark in a container on the
Redis container's network; the middle of three runs per column, and
8.2.0-BETA1 within 1 ms (beta 9 of 12). Run again on 2.1.2 on 2026-11-22
(symfony/cache 8.1.7, and 8.2.0-BETA2): every time matched within 1 ms, and
`beta` recomputed early for 9 of 12 in the middle run on both, and for 6 to 10
of 12 over those six runs.

```bash
php benchmarks/stampede.php modes=swr
```

```php
use Artigo\Cache\MemoLock;
use Artigo\Cache\Stale\RefreshQueue;
use Artigo\Cache\StaleCache;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\ItemInterface;

$lock = MemoLock::fromDsn('redis://127.0.0.1');
$inner = new RedisAdapter(RedisAdapter::createConnection('redis://127.0.0.1'));
$inner->setCallbackWrapper($lock);

$pool = new StaleCache($inner, staleSeconds: 60, staleIfErrorSeconds: 600, election: $lock);
$pool->setDeferrer($queue = new RefreshQueue());
$queue->flushAtShutdown();

$article = $pool->get('article-42', function (ItemInterface $item): Article {
    $item->expiresAfter(300);   // fresh for five minutes, served stale for one more

    return $this->load(42);
});
```

**What is stored.** A `Record`: the value and its three times — fresh until
the lifetime the callback set, servable while refreshed for `staleSeconds`
more, kept for `staleIfErrorSeconds` after that. The times travel inside the
value, so any marshaller carries them, `DeflateMarshaller` included, and the
inner pool keeps the Record for all three. A stale read is served with
`StaleCache::METADATA_STALE` in `$metadata`.

**What stays hard.** An invalidated value is never served stale: a tag
invalidation, a delete or a clear makes the inner pool answer a miss, and the
miss is computed. A forced `get()` (`$beta = INF`) recomputes over a fresh
value. A value whose callback sets no lifetime never turns stale; give the
decorator a `defaultLifetime`, not the inner pool.

**The inner pool is storage.** What it holds is Records: read and write
through the decorator. Its PSR-6 side is strict — `getItem()` on a stale value
is a miss — and `save()` stores with the decorator's times.

**On versioned-cache.** A refresh is the inner pool's forced `get()`, which
[`artigo/versioned-cache`](https://github.com/artigo-dev/versioned-cache)
guards like a miss: a delete that lands while the refresh computes refuses its
write, and a tag invalidation leaves it stale.

## Election: who refreshes

**The setup.** A stale read is served at once and asks an `Election` for the
refresh — without waiting. Whoever does not win serves the stale value and
moves on.

**The question.** When twelve servers read a stale value in the same second,
how many refresh it?

**Example.** `MemoLock` and `FleetLock` both implement `Election`: a lock of
their own, taken without blocking (`SET NX`, or a `symfony/lock` acquired
without waiting), so one process across the fleet refreshes and the other
eleven serve the stale value. `FlockElection`, the default when no election
is given, is a local `flock()`: one process per server refreshes, so twelve
servers refresh twelve times — still without a stampede, since each serves
the stale value meanwhile.

- **`FlockElection`:** no store to run, a scope of one machine, and no TTL: a
  refresh that hangs holds its lock file — one of 64 the keys share — until
  the process ends, which in a long-running worker may be never.
- **`MemoLock` or `FleetLock`:** one refresh across the fleet, and a lock that
  expires (`refreshLockTtlMs`, 30 000). Give a worker one of these.

So the election bounds the refreshes to one per server, or one per fleet, and
never makes a request wait for it.

**How it works.** The winner looks again — another process may have refreshed
it meanwhile — and then recomputes through the inner pool's forced `get()`, so
through its lock wrapper too. A refresh that throws keeps the stale value, and
logs it; so does an election that cannot be reached.

## RefreshQueue and the deferrer: when the refresh runs

**The setup.** Without a deferrer the refresh runs inline, and the request
that found the value stale gets the new one — and waits for it. With a
`RefreshQueue` as the deferrer it runs after the response, once per key.

**The question.** When does the refresh run, and who waits for it?

**Example.** A page reads 25 stale fragments. Inline, the page waits for 25
refreshes before it is sent. Queued, the page is sent with the 25 stale
fragments, and the 25 refreshes run once the response is out; a second stale
read of the same fragment during the page queues nothing, since one refresh
is already queued for that key.

- **Inline** (no deferrer): correct, and the request that happens upon a
  stale value pays for the refresh.
- **`RefreshQueue`:** the request pays nothing; the refresh runs on
  `kernel.terminate` or `console.terminate` in a Symfony application, through
  the shipped `TerminateSubscriber`, and between the messages a Messenger
  worker handles through the `kernel.reset` tag — the worker is one command,
  so its `console.terminate` comes only when it stops.

So the deferrer moves the refresh out of the response, and the queue holds
one refresh per key.

```yaml
services:
    Artigo\Cache\Stale\RefreshQueue:
        tags: [{ name: kernel.reset, method: reset }]
    Artigo\Cache\Stale\TerminateSubscriber:
        arguments: ['@Artigo\Cache\Stale\RefreshQueue']
        tags: [kernel.event_subscriber]
    app.cache.articles:
        class: Artigo\Cache\StaleCache
        arguments: ['@cache.app', 60, 600]
        calls: [[setDeferrer, ['@Artigo\Cache\Stale\RefreshQueue']]]
```

**How it works.**

- `messenger:consume` resets the tagged services after every message it
  handles, on Symfony 6.4 to 8.x, unless it runs with `--no-reset` (from 8.1,
  `--no-reset=N` resets every N messages); without a reset the queue waits
  for the worker to stop. A refresh that fails during a reset is logged rather
  than thrown, since a reset that throws stops the worker. A refresh that
  fails during a `flush()` keeps the others from nothing: they all run, and
  the first failure is thrown once they have.
- A queue holds 1,000 refreshes at most; `new RefreshQueue(maxRefreshes: 5000)`
  sets another limit. Past it a stale read is served without a refresh
  queued: the first read turned away logs a warning (give the queue a logger
  with `setLogger()`), and a read after the next flush queues the refresh
  again.
- Without a kernel, `flushAtShutdown()` runs it when the script ends; under
  PHP-FPM call `fastcgi_finish_request()` first, or the client waits for it.
- Any callable can be the deferrer. It is called with the refresh, a
  `\Closure` that takes no argument, and a key that is the same for every
  refresh of one cache key in this pool and differs for any other key or
  pool, so a deferrer can hold one refresh per key as `RefreshQueue` does.
  The key is passed only when the parameter that would receive it takes a
  string: a second parameter, or a variadic one, that is untyped or typed
  `string`, `?string`, `mixed` or a union holding one of them. Any other
  deferrer — one of a single parameter, or one whose second parameter is an
  `int` or a `\Closure ...$refreshes` — is called with the refresh alone.

## Stale-if-error

**The setup.** Past its stale time a value is computed like a miss, by the
request that reads it: the stale time is the oldest value you accept while the
origin answers.

**The question.** What is served when that computation fails — the database
is down, the API times out — and what does every request after it cost?

**Example.** `article-42` was fresh for five minutes and servable for one
more; `staleIfErrorSeconds` is 600. Seven minutes after it was written ten
requests read it at once, and the origin throws after 500 ms.

- **`staleIfErrorSeconds: 0`:** the exception reaches the caller.
- **`staleIfErrorSeconds: 600`:** one request computes, fails, and is served
  the old article. The failure is logged and remembered. The nine requests
  that waited behind it are served the old article as soon as it has failed;
  none of them computes. After the 600 seconds the exception reaches the
  caller.

**While the failure is remembered** the value is served like a stale one: at
once, with `METADATA_STALE`, and nobody computes what just failed. Once
`errorRetrySeconds` (1) have passed, one process is elected to try again, the
way a stale value is refreshed — after the response with a deferrer — and the
others keep serving the old article. An attempt that fails is remembered anew;
one that succeeds forgets the failure and stores the new value, so the next
read is a hit - or, when the callback chose not to store it (`$save = false`),
computes again, as with no failure. So past
the stale time a failing origin is asked once per `errorRetrySeconds` for each
key that is read, and a recovered origin is picked up within
`errorRetrySeconds` and one computation.

Measured through nginx and php-fpm, on versioned-cache with `MemoLock`, with a
computation that throws after 500 ms, by a harness that is not in this
repository; the 2.0 row needs that release, so these numbers are not re-run
with the rest:

| 10 concurrent reads, past the stale time | Computations | Median | Max |
|---|---|---|---|
| 2.0 | 10, one after another | 3.0 s | 5.0 s |
| 2.1, the first failure | 1 | 0.52 s | 0.52 s |
| 2.1, any read in the second after it | 0 | 10 ms | 10 ms |

**What it never does.** It never serves the kept value in place of a
computation that would succeed: with no failure remembered, a read past the
stale time computes, and the requests beside it wait for that value, as for
any miss. A failure counts only for the value it was about. One remembered
before the value was stored — a refresh failed, the next one succeeded — is
ignored. A value past `staleIfErrorSeconds`, a deleted one, and one a hard
invalidation reached are never served, whatever is remembered.

**Where the failure is kept.** In the inner pool, under a key of its own:
`stale.failed.` and a hash of the value's key, a float, for
`errorRetrySeconds` plus the refresh lock's TTL. It is written when a
computation fails and again when a retry starts, so that it stays remembered
while the retry runs, deleted when a retry succeeds, and read only for a value
past its stale time, so a hit, a miss and a stale read cost what they did. A
refresh of a stale value kept for errors that fails is remembered too. Inside
the stale time that changes nothing — a
stale read is served at once and may refresh, as before — but when the stale
time ends during an outage, nobody has to compute to find out. The failure's
time comes from the clock of the server where it failed, and is compared with
times other servers wrote: like every fresh or stale decision of this pool,
that assumes the app servers' clocks are synchronised (NTP).

**Without `MemoLock`**, the inner pool's lock is Symfony's `LockRegistry`.
Before symfony/cache 6.4.31, 7.3.9, 7.4.3 and 8.0.3 (and in all of 7.0 to 7.2),
a request that waited for that lock reads the stored value back instead of
computing it, so after a failed computation it gets the old value. This pool
serves it flagged as what it is (`METADATA_STALE`), and the next read
computes. A request that waited behind an unrelated key in the same lock slot
gets the old value too. Upgrade symfony/cache, or use `MemoLock`.

**The log.** Each failed computation is logged once, as an error with the
exception: the origin is down even though every request is answered. A
warning would go unseen with Symfony's defaults, whose logger without
Monolog writes from `error` up, and whose Monolog recipe writes a
request's log only when it holds an error. Since a failure is remembered,
an outage logs one error per key per retry time, not one per request.

So a value is kept for `staleIfErrorSeconds` after its stale time, to be
served only when computing it again fails, and a failing origin costs one
computation per retry time, not one per request.

## Soft serving: after a tag invalidation, too

**The setup.** A tag invalidation makes a value a miss, not a stale value: the
inner pool answers a miss. With `soft: true`, and an inner pool that keeps
what a soft invalidation reached —
[`artigo/versioned-cache`](https://github.com/artigo-dev/versioned-cache)
with `softGraceSeconds` — a value this pool wrote is served stale after its tag
falls as well, counted from the invalidation, and refreshed once.

**The question.** When a tag that 300 fragments carry falls, must every
visitor wait for the fragments to be recomputed?

**Example.** 300 fragments under one tag, twelve visitors reading 25 each, and
a 20 ms origin (versioned-cache's `benchmarks/soft.php`):

- **A hard invalidation:** 12 of 12 visitors wait for the origin, about
  560 ms each, and the 300 recomputations run during the responses.
- **A soft invalidation:** 0 of 12 wait; every visitor is served the old
  fragments at once, and the 300 recomputations run after the responses, one
  per fragment, elected through `MemoLock`.

So a soft invalidation trades serving the old value for up to the grace
against making visitors wait for the recomputation; what must never be served
again is invalidated hard.

```php
$inner = new VersionedRedisTagAwareAdapter($redis, softGraceSeconds: 600);
$inner->setCallbackWrapper($lock);   // before it is handed over

$pool = new StaleCache($inner, staleSeconds: 60, election: $lock, soft: true);

$pool->invalidateTags(['menu']);               // served stale, refreshed once
$pool->invalidateTags(['price-42'], hard: true); // a miss, as ever
```

**How it works.** The opt-in is the pool's: a value written by a pool without
`soft` is a miss after an invalidation whatever kind it was. A hard
invalidation, a delete and a clear stay hard. The pool puts its own callback
wrapper in front of the inner pool's, which a miss still goes through, so set
the inner pool's lock first. The wrapper asks the inner pool for the value
the invalidation reached and kept, through versioned-cache's
`AgedItemsInterface`; a value written soft and invalidated less than its stale
and error times ago comes back as it is, its lifetime ended at the
invalidation, and is served stale and refreshed. A soft `StaleCache` refuses
an inner pool that does not implement that interface or takes no callback
wrapper.

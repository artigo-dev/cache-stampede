# The locks: `MemoLock`, `FleetLock` and `PubSubRedisStore`

[← Back to the README](../README.md)

## MemoLock: one request through, woken by a message

**The setup.** `MemoLock` keeps the lock in Redis, named after the key. The
first miss takes it with `SET NX PX` and computes; when it has saved the value
it releases the lock with `DELEX … IFEQ` and sends one `PUBLISH` on a channel
named after the key. Every other miss finds the lock taken, subscribes to that
channel, and reads the pool when the message arrives.

**The question.** How does a waiter find out that the holder is done?

**Example.** The holder finishes computing `report-monthly` at 12:00:00.300.
A waiter that looks at the lock every 100 ms notices at 12:00:00.400 at the
latest, and on average 50 ms late. A waiter blocked on the channel is woken by
the holder's message at 12:00:00.301.

- **`LockRegistry`:** waiters retry `flock(LOCK_SH | LOCK_NB)` every 100 ms.
  Measured: 101 ms over the 300 ms origin call.
- **`FleetLock` on Symfony's `RedisStore`:** waiters retry the store every
  100 ms, as the Lock component does for every store that cannot block.
  Measured: 108 ms over the same call.
- **`MemoLock`:** waiters are woken by the message. Measured: 4 ms over the
  same call, and 2 ms after the one that computed it in the README's
  [Usage](../README.md#usage) example.

So the message buys the waiters about a hundred milliseconds per cold key,
and costs a second Redis connection: a connection in subscribe mode cannot
serve anything else, so the subscriber cannot be the one holding the lock.
`fromDsn()` opens both for you; hand the constructor no subscriber factory and
it degrades to polling the lock key every 25 ms (`pollIntervalMs`) — still one
origin call, just a slower wake-up.

**How it works.** The lock is four plain commands and no script on Redis 8.4
and Valkey 9: `SET NX PX` takes it, `SET … IFEQ` extends it while it is still
ours, `DELEX … IFEQ` (Valkey: `DELIFEQ`) releases it on the same condition,
and `PUBLISH` wakes the waiters. A conditional form the server lacks gets a
compare-and-set script, the same round trip as one `EVAL`: Redis before 8.4
scripts both, Valkey 8.1 only the release (it has `SET … IFEQ` from 8.1 and
`DELIFEQ` from 9), Valkey 8.0 both. The lock learns which dialect a connection
speaks from the server's refusals and remembers them for the connection, so
each native form is refused at most once per connection: one refused `SET`
for the extension, and for the release a refused `DELEX` and, where the server
has no `DELIFEQ` either, a refused `DELIFEQ`. The lock and its channel share a
cluster hash tag, so a cluster keeps the pair on one node.

A waiter looks at the lock's `PTTL` before every `SUBSCRIBE` and listens 100 ms
at a time: a lock that is already gone is not waited for at all, a lock about
to expire is waited for about that long, and a wake-up that went out in the
window before the subscription began — the holder was quick — costs one slice
rather than the whole `waitTimeoutMs`. After the wait the waiter reads the
item; a hit is served, and a miss — the holder died, or what it wrote expired
at once — makes it race for the lock again.

**Settings.** `lockTtlMs` (30 000) is how long the lock may be held before it
expires on its own; `waitTimeoutMs` (5 000) is how long a waiter waits before
it looks at the pool again; `pollIntervalMs` (25) is the poll without a
subscriber.

```php
new MemoLock($redis, lockTtlMs: 30_000, waitTimeoutMs: 5_000);
```

**Choosing the lock TTL:** set it to the longest the work should take. Too low
and the waiters see it expire and rebuild in parallel, which is the thing being
prevented; too high and a holder that dies keeps everybody waiting before one
of them retakes it.

## FleetLock: the same lock on any `symfony/lock` store

**The setup.** `FleetLock` takes a `symfony/lock` `LockFactory` and knows
nothing else. It has no Redis dependency of its own — no Redis class appears in
the file — so it locks on whatever store you already run: Redis, PostgreSQL,
Doctrine DBAL, Memcached, Zookeeper, anything the Lock component supports.

**The question.** What does the Lock component give up against a lock that
speaks Redis directly?

**Example.** The same twelve requests on `report-monthly`, the lock on
Symfony's `RedisStore`. One acquires and computes. The eleven others call
`acquireRead(true)`: the Lock component has no way for a store to announce
that a lock was released, so, with the exception of the two PostgreSQL stores,
which block natively, and Semaphore, whose waiters block on the exclusive lock
instead, a waiter finds out by retrying on a sleep of **about 100 ms** — on
Flock too, which cannot block on a read lock.
They are served 108 ms over the 300 ms, where `MemoLock`'s are served 4 ms
over it.

- **`FleetLock`:** one lock abstraction across the application, any store,
  one connection — and the component's wake-up, a 100 ms poll on most stores.
- **`FleetLock` on PostgreSQL:** the store blocks natively, so the poll does
  not apply: fleet-wide *and* woken immediately, without Redis anywhere.
- **`FleetLock` on [`PubSubRedisStore`](#pubsubredisstore-the-wake-up-on-the-lock-components-side):**
  the component's own waiters are woken by a message, 7 ms over the 300 ms.
- **`MemoLock`:** 4 ms, Redis only, a second connection.

So `FleetLock` is the lock for a fleet with no Redis, or with one lock story
for the whole application; what it gives up is only the wake-up latency, and
only on the stores that cannot block.

```php
use Artigo\Cache\FleetLock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

$pool->setCallbackWrapper(new FleetLock(new LockFactory(new RedisStore($redis))));
```

**How it works.** A miss creates the lock `cache.stampede.<key>` and tries to
acquire it without blocking. The winner computes, saves and releases. A loser
takes a *read* lock in blocking mode, so that the waiters wake together, as
`LockRegistry` does with `flock(LOCK_SH)`. The exclusive lock would hand it to
them one at a time, which measured costs little: twelve waiters on it were
served 15 ms over a 300 ms origin call on `PubSubRedisStore`, against 7 ms
on the read lock, and 106 ms against 108 ms on `RedisStore`, where the 100 ms
poll decides. A store that cannot do shared locks falls back to the exclusive
one, which is still correct. After ten attempts at a lock that is never let go, the caller
is served rather than kept waiting: the value is computed unprotected, and a
warning says so.

The exclusive-lock numbers come from their own runs; the read lock's are the
headline table's:

```bash
php benchmarks/stampede.php workers=12 resolverMs=300 hosts=12 modes=fleetlock,fleetlock-exclusive,fleetlock-pubsub,fleetlock-pubsub-exclusive
```

**Settings.** `ttl` (30.0 s) is how long the lock may be held; the `prefix`
(`cache.stampede.`) names the locks.

```php
new FleetLock($locks, ttl: 30.0);
```

## Which one to use

Both fix the same two things: the lock is visible to the whole fleet, and it is
named after the key rather than drawn from a fixed pool of files. On the
headline benchmark they are identical — **1 origin call out of 12, across 12
machines**. Neither is a cut-down version of the other. They differ in one
decision, and everything else follows from it: **what the lock is made of.**

| | `MemoLock` | `FleetLock` |
|---|---|---|
| lock lives in | Redis, four plain commands | any `symfony/lock` store |
| a waiter is woken by | a Pub/Sub message | a 100 ms poll\* |
| overhead over a 300 ms origin call | **4 ms** | 108 ms |
| origin calls, 12 requests / 12 servers | **1** | **1** |
| connections | two, the second subscribing† | one |
| needs | `ext-redis` or Relay; Redis 7.2+ or Valkey 8+, plain commands on Redis 8.4 / Valkey 9 | `symfony/lock` |
| outside a cache pool | `exclusively()`, `once()`, `KeepAlive` | — |

\* except on the PostgreSQL stores, which block natively — fleet-wide *and*
woken immediately, without Redis anywhere.
† the subscriber is optional; without it `MemoLock` polls the lock key. Still
one origin call, just a slower wake-up.

**The 4 ms / 108 ms is not a backend difference.** Both numbers come from the
same run against the same Redis: the benchmark wires `FleetLock` as
`new FleetLock(new LockFactory(new RedisStore($redis)))`. What separates them is
only how the waiter finds out, message versus poll.

**Reach for `MemoLock` when** Redis is already in the stack, the wait a waiter
inherits is worth 100 ms to you, and a second connection is not a problem. It
is also the only one of the two that locks things that are **not cache items**
— a thumbnail, an import, a provisioned resource — through `exclusively()` and
`once()`, with a `KeepAlive` for work that outlives its own lock TTL.

**Reach for `FleetLock` when** any of these hold:

- there is no Redis, and the fleet-wide store is PostgreSQL, Doctrine DBAL,
  Memcached, Zookeeper — anything the Lock component supports;
- there *is* Redis, but you would rather have one lock abstraction across the
  application than a second, Redis-shaped one;
- you cannot spare the second connection a blocking `SUBSCRIBE` needs;
- the store is PostgreSQL, in which case the poll does not apply at all;
- the store is `PubSubRedisStore`, in which case the poll does not apply
  either: the component's own waiters are woken by the message, and the Lock
  component keeps the whole lock story.

Running `FleetLock` on Redis is an ordinary choice, not a fallback — it is what
the benchmark measures. The only thing you give up is the wake-up latency.

## PubSubRedisStore: the wake-up on the Lock component's side

**The setup.** The gap between the two locks can be closed on the Lock side.
`PubSubRedisStore` is Symfony's `RedisStore` with the two blocking interfaces
the component offers, `BlockingStoreInterface` and
`BlockingSharedLockStoreInterface`. Its waiters block on a Pub/Sub channel
named after the key until the holder's `delete()` publishes on it, then try
again; the wait is bounded by the holder's `PTTL`, so a holder that died is not
waited for beyond its lock. Everything else — the tokens, the read locks, the
refresh, the Lua that guards them — is `RedisStore`'s own.

**The question.** Does the Lock component need a lock of its own in the Cache
component to get the wake-up, or only a store that can announce a release?

**Example.** The same twelve requests on a 300 ms resolver, one host
(`stampede.php modes=fleetlock-pubsub`), in the same runs as
[the headline table](measurements.md#one-cold-key-twelve-concurrent-requests):

| | origin calls | overhead over the resolver |
|---|---|---|
| `MemoLock` | 1 / 12 | 4 ms |
| `FleetLock` on `RedisStore` | 1 / 12 | 108 ms |
| `FleetLock` on `PubSubRedisStore` | 1 / 12 | **7 ms** |

Woken by a message, the Lock component's waiters were served 3 ms after the
one that computed it — 2 ms more than `MemoLock`'s own, a hundred less than the
poll, with no Redis command of their own in the cache.

So `FleetLock` on this store inherits the wake-up with no Redis code of its
own. The wake-up can therefore live in either place: in `MemoLock`, at 4 ms,
or inside `symfony/lock`, at 7 ms, where every Lock user would get it.

**How it works.** Like `MemoLock`, it needs a second connection for the
blocking `SUBSCRIBE`; `PubSubRedisStore::fromDsn()` opens both. A blocking
acquire tries, waits for the holder's word a slice of 100 ms at a time, never
longer than the holder's lock can live, and tries again; past `waitTimeoutMs`
(30 000) it throws `LockConflictedException`, as the component's own stores
do. `initialTtl` (300.0 s) is the locks' expiration delay, as `RedisStore`
takes it.

## Per-call overrides

**The setup.** A slow resolver wants a lock that outlasts it; a cheap key is
not worth locking at all. Symfony's `get()` has nowhere to say either — its
signature is `($key, $callback, $beta, &$metadata)` and `$beta` governs
probabilistic early expiry, not locking.

**The question.** How does one call borrow a different lock, or none, and
give the pool's own back afterwards?

**Example.** `setCallbackWrapper()` hands back whatever it replaced, so a
single call can borrow a different lock and give it back:

```php
// a longer lock, for this call only
$value = $lock->with(lockTtlMs: 30_000)->around(
    $pool,
    static fn () => $pool->get('external-api', $resolver),
);

// no lock at all, for a key not worth protecting
$value = MemoLock::unguarded($pool, static fn () => $pool->get('cheap', $resolver));
```

- **Written by hand:** swap the wrapper, call, swap it back — and the swapping
  back is the part that gets forgotten when the call throws.
- **`with()` and `around()`:** `with()` answers a copy, so the lock the pool
  normally uses is untouched, and the restoring happens in a `finally`.

So a per-call setting lives in the call, and the pool's lock is never left
changed.

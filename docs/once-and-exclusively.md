# Outside a cache pool: `once()`, `exclusively()` and `KeepAlive`

[← Back to the README](../README.md)

## `once()`: without a cache pool at all

**The setup.** The same lock, for the thing a cache is not: a thumbnail, a
generated file, a provisioned resource. One caller produces it; the rest wait
for **the thing** rather than for the lock, and carry on the moment it exists.

**The question.** When four requests ask for a thumbnail that does not exist
yet, how many render it, and what do the others wait for?

**Example.** Four requests for one thumbnail, 400 ms to render
([`examples/thumbnail.php`](../examples/thumbnail.php), four processes released
on a barrier):

```console
$ php examples/thumbnail.php
four requests for one thumbnail, 400 ms to render

  rendered after   402 ms  ->  artigo-thumbnail-example.txt
  waited   after   402 ms  ->  artigo-thumbnail-example.txt
  waited   after   402 ms  ->  artigo-thumbnail-example.txt
  waited   after   402 ms  ->  artigo-thumbnail-example.txt
```

- **A mutex** (`symfony/lock`, or `exclusively()` below): hands the lock to
  each of the four in turn, and each decides, again, whether to render.
- **`once()`:** one render, four answers, and the three waiters finish
  **within a millisecond** of the one that did the work — that is the message
  arriving, not a poll coming round.

So `once()` is for waiters that want the result: they are woken by a message,
look for the thing, and never take the lock themselves while it is there.

```php
$thumbnail = $lock->once(
    'thumbnail:'.$id,
    exists: static fn (): ?string => is_file($out) ? $out : null,
    make: function () use ($source, $out): string {
        $this->render($source, $out);

        return $out;
    },
);
```

**How it works.** `exists` is asked **before** any lock is taken, so a caller
arriving after the work is done never touches Redis for one. Returning `null`
means "not there yet". A waiter asks `exists` again after every wake-up; when
the holder died or produced nothing, it races for the lock, ten attempts at
most, and then produces the thing itself. `make` is handed a
[`KeepAlive`](#keepalive-work-that-outlasts-the-lock). A lock that cannot be
reached does not stop the work: the thing is produced unprotected and a
warning says so.

## `exclusively()`: a plain critical section

**The setup.** When the waiters want the lock rather than a result.

```php
$lock->exclusively('import:companies', function (): void {
    $this->importCompanies();
});
```

**The question.** Why have this beside
[`symfony/lock`](https://symfony.com/doc/current/lock.html), which is the
better mutex — it refreshes a held lock, releases on destruct and speaks to a
dozen stores?

**Example.** Eight workers holding a 200 ms section in turn, fully serialised
either way:

| | total | handover cost over eight |
|---|---|---|
| `symfony/lock` | 1 709 to 1 825 ms | 109 to 225 ms |
| **`exclusively()`** | **1 611 ms** | **11 ms** |

Eight processes released on one barrier, `symfony/lock` 8.1.6 on `RedisStore`
against `exclusively()`; ten runs of `symfony/lock`, whose range is given
because its poll waits a random time, and the middle of three runs of
`exclusively()`; measured with `benchmarks/exclusively.php` on 2026-11-22
and 2026-11-23 inside Docker Desktop (WSL2,
Windows 11, 24 CPUs), PHP 8.5.12 with phpredis 6.3.0, Redis 8.10.1, this
package 2.1.2:

```bash
php benchmarks/exclusively.php mode=symfony
php benchmarks/exclusively.php mode=memolock
```

- **`symfony/lock`:** each handover costs the next worker's poll, 16 to 32 ms
  here, 109 to 225 ms over eight.
- **`exclusively()`:** each handover is a message, 11 ms over eight.

So the wake-up pays here too, which is the reason this is worth having beside
`symfony/lock`; what `symfony/lock` still does better is below.

**Unlike everything else here, this throws.** A cache that cannot take a lock
computes anyway, because a duplicated computation beats a failed request; a
caller who asked that two workers never run something at once would rather hear
that it did not run, so an unreachable lock, or one still held after ten
attempts, is a `LockUnavailable`.

**What `symfony/lock` still does better:** it **releases on destruct**, so a
process that `exit()`s or fatals mid-section hands the lock back at shutdown,
where a `finally` does not run at all. What that case costs here is the lock's
remaining TTL, which is also the longest a waiter will wait for it — and a
destructor is itself best effort, skipped on a hard crash.

## KeepAlive: work that outlasts the lock

**The setup.** A lock has a TTL so that a holder that dies does not keep
everybody waiting forever. An import that takes longer than the TTL would have
its lock run out underneath it, and the next worker would start the same
import beside it.

**The question.** How does a long piece of work keep its lock without
guessing the TTL up front?

**Example.** The closure holding the lock is handed a `KeepAlive`. Call it as
the work goes and the expiry moves out by a full TTL each time:

```php
$lock->exclusively('import:companies', function (KeepAlive $keepAlive) use ($rows): void {
    foreach ($rows as $row) {
        $this->import($row);
        $keepAlive();
    }
});
```

- **A TTL long enough for the worst case:** a holder that dies keeps the
  waiters waiting for that whole TTL.
- **A `KeepAlive` per row:** the lock is extended only while the work goes on,
  and a holder that dies is waited for one TTL at most.

So the TTL can be short, and the work says as it goes that it is still alive.

**How it works.** `once()` hands one to its `make` for the same reason.
Closures that do not want it simply declare no parameter. It **throws**
`LockUnavailable` if the lock has gone — expired, and taken by somebody else
while the work ran. That is the one thing a long section needs to hear, and a
return value is too easy not to read; `symfony/lock`'s `refresh()` throws for
the same reason. The extension is a single `SET … IFEQ` (Redis 8.4, Valkey
8.1), or a compare-and-set script where the server has no such option: either
way it rewrites the lock only while it still carries our token, so nobody
else's lock is ever touched. It takes an optional number of milliseconds for
the extension; the lock's own TTL is the default.

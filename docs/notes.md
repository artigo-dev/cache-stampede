# Notes worth knowing

[← Back to the README](../README.md)

- Setting the wrapper explicitly **also overrides Symfony's default of disabling
  locking under the CLI SAPI**, which is what makes any of this measurable from
  a script. Symfony also disables `LockRegistry` entirely on Windows; neither
  lock here does.
- **Probabilistic early expiration is not what these locks do**, and `$beta` is
  not read. Recomputing a little before expiry, at random, and hoping the herd
  never forms is the *other* answer to this problem — the answer these locks
  exist because of — and Symfony decides it upstream anyway, before the wrapper
  is ever called.
- **`INF` is honoured**, the way `LockRegistry` honours it: a forced recompute
  waits for the lock like any other waiter and then takes it. It arrives on the
  same parameter but means something explicit — recompute now — so it still
  gets you a value computed after you asked, and it gets it behind the lock
  rather than beside it. The item is read again after the wait, so a pool that
  guards its writes compares the forced one with what the holder left.
- Neither lock will ever be the reason a request fails: an unreachable Redis or
  lock store degrades to computing without protection, and says so through the
  PSR-3 logger. A lock that cannot be handed back once the value is computed is
  logged and left to expire; the value is still served.
- **On Symfony 8.2, a save that fails reaches the caller.** The lock that
  computes an item saves it itself, and passes what `save()` answered back
  through the `$setMetadata` closure Symfony hands every wrapper, so a refused
  or failed write shows up in `get()`'s `$metadata` as
  `ItemInterface::METADATA_SAVE_FAILED`, as it does without a wrapper. The
  value is returned either way. **Before 8.2 a failed save is reported only by a pool with no
  wrapper at all.** With symfony/cache-contracts 3.8, which adds the key, the
  contracts' `CacheTrait` reports a failed save when it saves the item itself:
  under the CLI, where Symfony sets no wrapper, or after
  `setCallbackWrapper(null)`. A wrapper that saves inside its lock clears
  `$save`, so `CacheTrait` saves nothing and reports nothing. That is
  Symfony's own `LockRegistry` on 6.4 to 8.1, which does not look at what
  `save()` answered, and it is both locks here: the `$setMetadata` closure of
  those versions takes the item alone, so the answer they pass is dropped. On
  6.4 to 8.1 the locks here report what `LockRegistry` reports, which is
  nothing; the one difference is the CLI, where Symfony's default is off and a
  failed save is reported, and these locks stay on. With older contracts there
  is no such key.
- **Neither lock waits into `max_execution_time`.** A second before the limit
  the waiter stops and computes unprotected, as `LockRegistry` does, rather
  than be killed with nothing computed and nothing served; `exclusively()`
  throws `LockUnavailable` instead, since running beside the holder is the one
  thing it must not do. A limit already past when the wait begins is ignored,
  since it then counts CPU time or was restarted by `set_time_limit()`.
- **A waiter looks at the lock before every `SUBSCRIBE`** and listens 100 ms
  at a time. A lock that is already gone is not waited for at all, a lock
  about to expire is waited for about that long, and a wake-up that went out
  in the window before the subscription began — the holder was quick — costs
  one slice rather than the whole `waitTimeoutMs`. That slice is
  `LockRegistry`'s own poll granularity, so on a missed message the two are
  even; on a delivered one `MemoLock`'s waiters are served a millisecond after
  the one that computed it. The subscriber connection is opened once per lock
  instance and kept.
- **Waiters take a read lock**, so they wake together, as `LockRegistry` does
  with `flock(LOCK_SH)`. Acquiring the exclusive one hands it over one at a
  time; measured with twelve waiters on a 300 ms call, that is 12 ms over the
  call on `PubSubRedisStore` against 7 ms on the read lock, and no difference
  on `RedisStore` (106 ms against 108 ms), where the 100 ms poll decides
  (the command is in [locks.md](locks.md#fleetlock-the-same-lock-on-any-symfonylock-store)).

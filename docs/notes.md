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
- **A read timeout on phpredis before 6.4 leaves the connection one answer
  behind.** When an answer does not come within the read timeout, phpredis
  before 6.4 can keep the connection open, and the answer that comes late is
  read as the answer to the next command; every answer after that one is late
  too, until the connection closes. On a lock, a `SET NX` that set the key
  then reads as refused, and one that was refused reads as having set it, so
  a process can believe it holds a lock that another process holds. So
  `MemoLock` and `PubSubRedisStore` close a connection whose command failed in
  a way that can leave such an answer, and the same client opens a new one
  for its next command. An error the server answered with, such as `OOM` or
  `READONLY`, leaves it open, since that answer was read in full. On a Redis
  Cluster, phpredis drops a node's connection itself when one of its own
  commands fails while reading the answer, so a cluster connection is closed
  only after a reply cut short, or a raw command that waited out its read
  timeout; one that failed sooner was redirected or refused, and left nothing
  behind. phpredis 6.3 can also leave an answer behind on a cluster after a
  timed-out `ASKING` or `READONLY` it sends ahead of a command during a slot
  move or a replica read, and after a command sent to one node, such as the
  `SCRIPT LOAD` of `RedisStore`; those are not closed here. The fix is on the
  phpredis 6.4 line, out as a release candidate, which closes such a
  connection itself, those included.
- **A lock whose command failed may stay until its TTL ends.** What the
  server had not run yet when the connection closed is either run late or
  dropped. A `SET NX` whose answer could not be read is given back with one
  delete that acts only on its own token: by the cache wrapper and `once()`
  once their computation is done, even when it throws, which gives a server
  that stalled time to answer again, and by `exclusively()` and the refresh
  election at once, since nothing follows there. A `SET NX` that was never
  sent, because the connection could not be opened, took nothing and is not
  given back. That delete waits at most 100 ms for each answer; a connection
  that has to be opened for it may take the client's connect timeout. On a
  Redis Cluster it is sent only when the cluster's read timeout is 100 ms or
  less, since its node connections keep their own, so in practice it is not
  sent there. When it fails, the process sends no give-back for 5 seconds, or
  for ten times as long as the failed one took, whichever is longer, so an
  outage costs one such attempt every few seconds at most. The pause holds
  for the whole process: one server that is down pauses the give-backs to
  every other. A lock it could not give back stays, and the next request
  waits for it as for a holder that died; so does a lock whose release got no
  answer.
- **Before phpredis 6.4, a closed cluster connection fails its replica
  reads.** On a Redis Cluster connection set to read from replicas, every read
  it sends to a replica after it was closed fails, with an error rather than a
  wrong answer. `MemoLock` says so once per connection, through the logger it
  was handed: Symfony's for the cache wrapper, the one passed to
  `exclusively()` or `once()`. `tryLock()` and `PubSubRedisStore` are handed
  none, and say nothing.
- **A Redis Cluster node that is down costs a release one cluster timeout,
  now and then.** During a slot move the release and the extension follow
  the redirect, through phpredis's own method for the command, or through
  the script after a raw command, which phpredis does not redirect. When the
  node is down instead, that detour looks for it on the other nodes for up to
  the cluster's timeout before it fails. After one failed, the process sends
  these two commands to a cluster only raw, which fails at once, for 5
  seconds or ten times as long as the failure took, whichever is longer; the
  pause holds for the whole process, every cluster included. On a server
  without the native commands (Redis before 8.4, Valkey before 9 for the
  release) the script is the only form: it goes out every time and is not
  paused.
- **A cluster read timeout raised after the connection was built is not
  seen.** A `RedisCluster`'s node connections keep the read timeout they were
  opened with, and phpredis does not tell it; the lock takes the smaller of
  the `OPT_READ_TIMEOUT` option and `default_socket_timeout`. After a
  `setOption()` that raised the option, a raw command that waited out the
  timeout its node really has looks like one that failed sooner: it is not
  closed, and the release goes on to the script.
- **`FleetLock` cannot close anything.** It holds a `LockFactory` and nothing
  else, so on Symfony's `RedisStore` with phpredis before 6.4 a lock script
  whose answer timed out can leave that answer for the next one, as above.
  The fix is on the phpredis 6.4 line, out as a release candidate;
  `PubSubRedisStore` closes its connection like `MemoLock`.
- **Sharing the cache pool's connection.** Handing a lock the pool's own
  `$redis`, as in `new MemoLock($redis)` or `new RedisStore($redis)`, makes
  the cache and the lock one connection. When a command of `MemoLock` or
  `PubSubRedisStore` fails and may have left an answer on it, the lock closes
  it: the pool's next command opens it again, and whatever the connection
  held is gone, such as an open `MULTI` or `WATCH` and a name set with
  `CLIENT SETNAME`. Symfony's `RedisStore` closes nothing. The other way
  round is not covered either: Symfony's adapters do not close a connection
  whose command timed out, so before phpredis 6.4 a lock command can read the
  answer a cache command left behind. `fromDsn()` gives the lock connections
  of its own.
- **A save that fails reaches the caller.** The lock that computes an item
  saves it itself, before it releases the lock, and clears `$save` so that
  Symfony does not save it again. It passes what `save()` answered back
  through the `$setMetadata` closure Symfony hands every wrapper. On Symfony
  8.2 that closure takes the answer, so a refused or failed write shows up in
  `get()`'s `$metadata` as `ItemInterface::METADATA_SAVE_FAILED`, as it does
  without a wrapper. On 6.4 to 8.1 the closure takes the item alone and would
  lose the answer, so after a failed save the lock leaves `$save` set instead:
  Symfony saves the item once more, and when that save fails too,
  symfony/cache-contracts 3.8.1 and later report it under the same key. Older
  contracts have no such key and report nothing. A wrapper called without a
  `$setMetadata` closure, which Symfony never does, leaves `$save` set the
  same way. The value is returned either way.
- **That second save has a price.** A pool that refuses every write is asked
  twice for each value computed: with the store down that is its timeout
  twice, and Symfony's warning twice. A `ChainAdapter` answers false when one
  of its pools failed, so the others are written twice. With contracts older
  than 3.8.1 the second save is still made and nothing is reported; what is
  left is the second try. And the second save comes after the lock is
  released: if it works, the value lands a moment after the waiters were
  woken, so a waiter that already looked finds nothing, takes the lock and
  computes the value once more. A pool that guards its writes compares both
  saves with the same read: versioned-cache refuses a write computed across a
  delete the second time too, and nothing stale is stored. That holds from
  versioned-cache 2.3, which is why this package conflicts with older ones:
  they forgot a read older than the guard window after refusing once, and
  the second save went through. Symfony's own
  `LockRegistry` on 6.4 to 8.1 saves once, does not look at what `save()`
  answered, and reports nothing.
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
- **A phpredis `RedisCluster` subscriber cannot listen for 100 ms.** Its node
  connections keep the read timeout they were opened with, whatever
  `setOption()` says afterwards, so one `SUBSCRIBE` would listen for that whole
  timeout, a minute by default. With such a subscriber the waiters of
  `MemoLock` poll the lock every `pollIntervalMs` instead, about 40 `EXISTS`
  a second each with the defaults, and those of `PubSubRedisStore` every
  25 ms within their slice: still bounded by `waitTimeoutMs`, woken up to a
  poll late. Such a subscriber is let go, not closed, since it may be a
  connection the application still uses, and the factory is not called
  again. A factory that returns a plain `\Redis` connection to any one node
  of the cluster keeps the message: the cluster passes a `PUBLISH` on to
  every node.
- **Waiters take a read lock**, so they wake together, as `LockRegistry` does
  with `flock(LOCK_SH)`. Acquiring the exclusive one hands it over one at a
  time; measured with twelve waiters on a 300 ms call, that is 12 ms over the
  call on `PubSubRedisStore` against 7 ms on the read lock, and no difference
  on `RedisStore` (106 ms against 108 ms), where the 100 ms poll decides
  (the command is in [locks.md](locks.md#fleetlock-the-same-lock-on-any-symfonylock-store)).

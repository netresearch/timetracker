# ADR-019: Session Storage and Lock-Contention Strategy

**Status:** Accepted — file-based sessions retained; PHP session write-lock released
early for read-only data GETs ([#534](https://github.com/netresearch/timetracker/pull/534),
[#537](https://github.com/netresearch/timetracker/pull/537)). A non-locking shared
backend (Valkey/Redis) is designed but **deferred** — see *Later options*.
**Date:** 2026-07-04
**Relates to:** [ADR-005](ADR-005-caching-strategy.md) (APCu, node-local caching — same
"why not a shared store yet" reasoning), [ADR-008](ADR-008-database-performance-optimization.md)
and [ADR-012](ADR-012-performance-optimization-strategy.md) (performance lineage —
this is a concurrency, not a query, bottleneck), [ADR-011](ADR-011-security-architecture.md)
(session-based authentication), [ADR-018](ADR-018-authentication-extension.md) (the auth
flows that *write* the session).

## Context

### The symptom

The SolidJS tracking page ([ADR-016](ADR-016-solidjs-frontend-rewrite.md)) hydrates by
firing its grid queries **in parallel** — customers, projects, activities, ticket
systems, users, teams, time summaries — as ~9 concurrent `GET`s that all carry the same
session cookie. In production these requests formed a **staircase**: each one fast in
isolation but progressively slower when fired together, e.g. the *same* `/getTicketSystems`
endpoint measured ~121 ms and ~529 ms in one page load depending only on its position in
the queue. Measured locally the effect is smaller but identical in shape: ~15 ms alone
versus 16/23/34/42 ms fired together (recorded in
[`ReleaseSessionLockSubscriber`](../../src/EventSubscriber/ReleaseSessionLockSubscriber.php)).

### The root cause — PHP's session file lock (verified in the running container)

- `session.save_handler = files`, `session.save_path` empty → the native PHP **file**
  session handler ([config/packages/framework.yaml](../../config/packages/framework.yaml),
  `handler_id: ~`, `storage_factory_id: session.storage.factory.native`).
- The native handler takes an **exclusive `flock()`** on the session file at
  `session_start()` and holds it until the response is sent (or `session_write_close()`
  is called). Two requests sharing one cookie therefore run **serialized**, not parallel —
  the second blocks on the first's lock. This is a well-known PHP behaviour, not a bug in
  this app (see *References*).
- It is pure overhead here because **almost every request only reads the session.** What
  the session actually holds is small and auth-only:
  - the Symfony security token (written at login, read thereafter);
  - a transient scheb `TwoFactorToken` during an in-progress 2FA login;
  - `PENDING_SECRET` during TOTP enrollment;
  - a WebAuthn challenge during a passkey ceremony.

### Why the reads really are reads (verified)

- **CSRF is stateless** ([framework.yaml](../../config/packages/framework.yaml):
  `csrf_protection.stateless_token_ids: ['authenticate', 'logout']`) — form/GET rendering
  does **not** write a token into the session. This was the one plausible hidden per-request
  writer; it is absent.
- **No per-request session-ID rotation** — the firewall
  ([security.yaml](../../config/packages/security.yaml)) does not override the session
  authentication strategy, so Symfony's default `migrate` regenerates the ID **only at
  authentication** (login), never per request.
- **`session.lazy_write = 1`** — an unchanged session is not rewritten on close, so
  releasing the lock on a read is genuinely free.
- The complete set of **session writers** is a small, stable, sequential list of auth
  transitions: login (+ ID `migrate`), logout (`invalidate_session: true`), the `/2fa_check`
  step, TOTP enroll start/confirm, the WebAuthn `options`/`result` paths, and `switch_user`
  impersonation. None of them run concurrently with themselves for one user; the only
  concurrent traffic is the read-only grid fetch.

### What else is on node-local storage today (for the deferral rationale)

Sessions are not the only per-node state, and the container currently has **no Redis or
Memcached client** (`extension_loaded('redis') === false`, `extension_loaded('memcached')
=== false`; `apcu` is present but PHP has **no** APCu session handler):

| Subsystem | Store today | Scope |
|---|---|---|
| Sessions | files | one container |
| Doctrine result cache + `QueryCacheService`/`OptimizedEntryRepository` | `cache.app` = **APCu** ([ADR-005](ADR-005-caching-strategy.md)) | one container |
| Login-throttling counters (`max_attempts: 5`) | app cache pool = APCu | one container |
| Doctrine metadata/compile cache | `cache.system` | one container |

APCu is shared across all PHP-FPM workers **within** a container, so at the current
**single-container** deployment (the `:profiling-<sha>` hot-deploy runs one live app
container) every one of these is coherent and optimal. They only become a problem — all at
once — the day a second app instance exists.

## Decision

1. **Keep native file-based sessions.** No change to `handler_id`.
2. **Release the write-lock early for read-only data GETs.** A `kernel.controller`
   subscriber ([`ReleaseSessionLockSubscriber`](../../src/EventSubscriber/ReleaseSessionLockSubscriber.php))
   calls `$session->save()` for `GET` requests whose route is on an **allowlist** of the
   grid/option/summary endpoints. Shipped in two steps: [#534](https://github.com/netresearch/timetracker/pull/534)
   (initial 6 routes + PHP-FPM `pm` tuning) and [#537](https://github.com/netresearch/timetracker/pull/537)
   (extended to all **19** hot read routes — a single omitted reader re-serializes the whole
   parallel batch).
3. **The allowlist is deliberately fail-safe.** A new read endpoint that is forgotten is
   merely *slow* (keeps its lock), never *wrong*. Only `GET` is released, so a route that
   also accepts `POST` keeps its lock on the writing verb.
4. **Do not use APCu for sessions.** It is volatile (wiped on every FPM reload / deploy →
   would log out all users each release), evictable under memory pressure (sessions would
   vanish and fight the query cache for `apc.shm_size`), and node-local — strictly worse
   than files for a session store.
5. **Do not add Redis/Valkey now.** At single-container scale, node-local files are correct
   and a shared in-memory store would only add a network hop and a service to operate. The
   design is kept ready (below) for the day it is justified.

## Considered options

### Rejected: read-only-by-default via a *denylist* of writers
Invert the subscriber to release the lock for **all** requests except a hardcoded list of
the ~8 auth/ceremony writers. Matches the "read-only by default" intuition but is
**fail-open**: a future writer we forget to denylist would silently lose its session write
(a subtle auth bug). The allowlist's fail-safe direction is worth more than the small
maintenance saving.

### Rejected as default: `PdoSessionHandler` with `LOCK_NONE` on MariaDB
Non-locking, and needs **no new infrastructure** (reuses the existing DB). Rejected because
it moves session data onto the **SQL trust surface**: an application SQL-injection with
arbitrary read would gain session hijacking, which file sessions do not expose. Restoring
that isolation needs a **separate DB user** with grants only on the `sessions` table (the
app connection today is a single `timetracker` user — [doctrine.yaml](../../config/packages/doctrine.yaml)),
which erodes the "no new moving parts" advantage. It also only removes one of three
filesystem writers (logs and `var/cache` remain), so it does not buy a read-only rootfs.

### Rejected: APCu session handler
See Decision §4.

## Later options (the trigger and the ready design)

**Trigger:** the first time production runs **more than one app instance** — horizontal
scale, or a rolling/blue-green deploy where two instances serve simultaneously. At that
point file sessions break (a user is pinned to one container or logged out on reroute), and
a **shared** store is required. Valkey/Redis then pays for itself across **three** node-local
subsystems at once: sessions, login-throttling (a true global `max_attempts`), and the
Doctrine result/query cache (coherent cross-instance invalidation). `cache.system` should
stay local either way.

**Ready design — Valkey as an *optional*, non-locking session backend behind one env var:**

```yaml
# config/packages/framework.yaml
framework:
    session:
        handler_id: '%env(SESSION_DSN)%'   # currently: ~
```
```dotenv
# .env — default keeps native files (locking, but the subscriber handles it)
SESSION_DSN=file://%kernel.project_dir%/var/sessions
# prod-with-valkey would override:  SESSION_DSN=redis://valkey:6379
```

- Unset/`file://` → native file sessions + the subscriber = today's behaviour.
- `redis://…` → Symfony builds a **non-locking** `RedisSessionHandler` → zero serialization
  on every route, sessions shared across instances.
- **Keep the subscriber in both modes.** With Valkey it is a near-no-op (thanks to
  `lazy_write`), and it remains the graceful-degradation path if Valkey is disabled or down
  and sessions fall back to files.
- **Prerequisite:** the image has no Redis client — adding one (`pecl install redis`, or
  `composer require predis/predis`) is a required build step, so Valkey is not a
  "just add a container" change.
- **Do not enable Redis session locking** (`redis.session.locking_enabled`) — it
  reintroduces the exact serialization/random-logout problem this ADR removes (see
  *References*).

## Consequences

**Positive**
- The measured parallel-fetch serialization is removed on the hot paths, with **zero new
  infrastructure** and no dependency added.
- Session data stays **off the SQL surface** (unlike the PDO option).
- The allowlist is fail-safe: a missed endpoint is slow, never incorrect.
- The subscriber is **backend-agnostic** — if Valkey is adopted later, it stays correct and
  harmless, so this decision does not have to be unwound.

**Negative / limits**
- The allowlist must be **extended when a new hot read GET is added** (documented in the
  subscriber's class docblock and covered by
  [`ReleaseSessionLockSubscriberTest`](../../tests/EventSubscriber/ReleaseSessionLockSubscriberTest.php)).
- File sessions **do not survive multi-instance** operation — this is the explicit blocker
  that triggers the Valkey work above.
- PHP-FPM worker tuning from [#534](https://github.com/netresearch/timetracker/pull/534)
  remains part of the fix (more workers to absorb the brief windows where a lock is still
  held on a writing request).

## References

- ma.ttias.be — *PHP Session Locking: How To Prevent Sessions Blocking* —
  <https://ma.ttias.be/php-session-locking-prevent-sessions-blocking-in-requests/>
- Konr Ness — *PHP Session Locks – How to Prevent Blocking Requests* —
  <https://konrness.com/php5/how-to-prevent-blocking-php-requests/>
- phpredis — *session support doesn't lock the session* (non-locking by default; opt-in
  locking since 4.1) — <https://github.com/phpredis/phpredis/issues/37>
- fsck.sh — *Redis Session Locking Pitfalls in Symfony: Why Your Users Get Random Logouts* —
  <https://fsck.sh/en/blog/redis-session-locking-pitfalls-symfony/>
- magento/magento2#34758 — the same non-locking-vs-SPA scenario —
  <https://github.com/magento/magento2/issues/34758>
- Symfony 8 session handler configuration —
  <https://github.com/symfony/symfony-docs/blob/8.0/session.rst>

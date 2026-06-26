# Admin-gated production profiler — design

- **Date:** 2026-06-26
- **Status:** Approved (design); implementation plan pending
- **Author:** Sebastian Mendel

## Goal

Let an **admin enable the full Symfony web profiler (web-debug-toolbar + `/_profiler`) for their own session in production**, on demand and time-boxed, so real-world production profiling data can be captured and attached to issue reports.

Motivation: profiling problems often need real production data. Developers may be able to run profiling on prod, but other admins cannot, and so cannot provide detailed data when reporting an issue (e.g. the recently-observed slow first load of `/ui/admin` Customers/Projects). This feature gives any admin a self-service way to collect that data.

## Non-goals (YAGNI)

- No password re-authentication or IP-pinning to enable profiling (can be added later).
- No web UI to run `bin/console` commands.
- No purpose-built custom diagnostics panel — we expose the real Symfony profiler, not a re-implementation.
- No profiling for non-admins.

## Approach

**Dormant by default, opt-in per admin, time-boxed.** The profiler is shipped to prod but collects nothing until a specific admin turns it on for their own session. This keeps the default production behaviour, overhead, and attack surface unchanged.

## Architecture & flow

1. **Ship the bundles to prod, dormant.**
   - Move `symfony/web-profiler-bundle` and `symfony/debug-bundle` from `require-dev` to `require` in `composer.json` (so `composer install --no-dev` in the prod image still installs them).
   - Enable `WebProfilerBundle` (and `DebugBundle`) for `prod` in `config/bundles.php` (currently `['dev' => true, 'test' => true]`).
   - Add `config/packages/prod/web_profiler.yaml`:
     - `framework.profiler: { enabled: true, collect: false }` — the profiler service exists but collects on **no** request unless explicitly enabled per-request.
     - `web_profiler: { toolbar: true, intercept_redirects: false }` — the toolbar only injects when a profile was actually collected, so `collect: false` means no toolbar for normal traffic.

2. **Per-admin, time-boxed opt-in (session-based).**
   - `POST /profiling/enable` (ROLE_ADMIN) → sets session key `profiler_until = now + TTL` (TTL = **30 minutes**).
   - `POST /profiling/disable` (ROLE_ADMIN) → clears the key and purges that session's collected profiles.
   - `GET /profiling/status` (ROLE_ADMIN) → `{ active: bool, remainingSeconds: int }`.
   - Session-based = per-browser, exactly "enable for myself". Auto-expires; manual off supported.

3. **Gate listener (`kernel.request`, high priority, after firewall).**
   - If the authenticated token's user has `ROLE_ADMIN` **and** `profiler_until` is set and in the future → call `Profiler::enable()` for this request.
   - Otherwise: do nothing (profiler stays dormant).
   - Reads the real authenticated user from the security token — never a client-supplied header.
   - Never enables on the login route (`_login`) so credentials are never collected.

4. **Make the data useful (Doctrine query panel).**
   - `config/packages/doctrine.yaml` sets `dbal.profiling: '%kernel.debug%'` → `false` in prod, which leaves the profiler's DB panel empty.
   - In `config/packages/prod/doctrine.yaml` (or the prod profiler config) enable `dbal.profiling: true` so the query list, timings, and `EXPLAIN` appear. Small per-request overhead; acceptable and only matters for the (rare) profiled requests in practice. Keep `profiling_collect_backtrace: false` to bound memory.
   - Caveat to verify during implementation: some collectors (e.g. the **Time** panel via `symfony/stopwatch`) are normally wired only under `kernel.debug`. Confirm which panels populate in an `APP_ENV=prod` build with the profiler enabled, and pull `symfony/stopwatch` into `require` if the timeline panel is wanted in prod.

5. **Lock down the profiler routes.**
   - Load the profiler routes in prod: add `config/routes/prod/web_profiler.yaml` (mirroring the existing `config/routes/dev/web_profiler.yaml`) exposing `/_wdt` and `/_profiler`.
   - In `config/packages/security.yaml`, add **above** the `^/ → IS_AUTHENTICATED_FULLY` catch-all:
     - `{ path: ^/_(profiler|wdt), roles: ROLE_ADMIN }`
   - The prod `dev` firewall pattern (`^/(_(profiler|wdt)|...)`) is dev-only; in prod these paths fall under the `main` firewall, so they are authenticated + now ROLE_ADMIN-gated.

6. **Reduce panel exposure.**
   - A small `CompilerPass` removes the most sensitive data collectors in prod so their panels are absent: the **dump** collector (`VarDumper`) and the **config** collector (`Symfony\Component\HttpKernel\DataCollector\ConfigDataCollector`), plus scrub server/env params from the request collector where feasible.
   - Remaining panels: Database (Doctrine), Time, Memory, Request/Response, Routing, Events, Logs, Cache — the perf-relevant ones.
   - Exact collector service IDs to be confirmed during implementation.

7. **Frontend toggle (admin-only).**
   - A ROLE_ADMIN-only control on the **Settings** page: "Production profiling" with on/off, remaining time, and a link to `/_profiler`.
   - Visible only when `hasRole('ROLE_ADMIN')` (helper already exists in `frontend/src/config.ts`).
   - Calls `/profiling/enable|disable|status`; reflects state and counts down remaining time.

## Components (units)

| Unit | Responsibility | Interface | Depends on |
|------|----------------|-----------|------------|
| `ProfilingSession` service | Enable/disable/inspect the time-boxed opt-in | `enable(): void`, `disable(): void`, `isActive(): bool`, `remainingSeconds(): int` | Session, clock |
| `ProfilingController` | `enable`/`disable`/`status` actions | 3 routes, JSON, ROLE_ADMIN | `ProfilingSession` |
| `EnableProfilerListener` | Per-request gate that flips the profiler on | `kernel.request` subscriber | `Profiler`, security token, `ProfilingSession` |
| `RemoveSensitiveCollectorsPass` | Drop dump/config collectors in prod | compiler pass | container |
| Config + infra | composer move, bundles.php, prod `web_profiler.yaml` + `doctrine.yaml`, prod routes, security access_control | — | — |
| Frontend `ProfilingToggle` | Admin-only on/off + status + `/_profiler` link | Solid component + API hook | `config.ts` `hasRole`, api client |
| Profile hygiene | Purge on disable + bounded max-age storage | (storage DSN / disable hook) | profiler storage |

## Lifecycle / hygiene

- **Time-box:** 30-minute TTL, auto-expires; the listener treats an expired flag as off.
- **Purge:** on `disable`, delete profiles collected for that session; storage uses a bounded max-age (e.g. `file:%kernel.cache_dir%/profiler` with a periodic/maxage purge) so nothing accumulates indefinitely.
- **Login never profiled** (credential safety).

## Security model (explicitly accepted)

- Only `ROLE_ADMIN` can enable; collection is the enabling admin's own authenticated requests only; time-boxed; `/_profiler` + `/_wdt` are `ROLE_ADMIN`-locked under the `main` firewall.
- **Accepted residual risks:**
  1. Profiler panels can reveal server/env data and request bodies to anyone who can open `/_profiler` (any `ROLE_ADMIN`). Mitigated by removing the dump/config collectors and never profiling the login route; the remainder is accepted within the ROLE_ADMIN trust boundary.
  2. One admin can view another admin's collected profile (listed by token). Accepted within ROLE_ADMIN trust.
  3. Stored profiles hold sensitive request data → isolated dir + purge-on-disable + bounded max-age.
  4. Larger attack surface; an admin session hijack also reaches the profiler. Mitigated by dormant-by-default + 30-min time-box.

## Testing strategy

**Backend**
- `EnableProfilerListener`: enables the profiler **only** when user is ROLE_ADMIN **and** flag active; not for non-admins, not when expired, not on `_login`.
- `ProfilingController`: `enable`/`disable`/`status` require ROLE_ADMIN (403 otherwise); `enable` sets a future expiry; `disable` clears it; `status` reports remaining time.
- `ProfilingSession`: TTL math, expiry boundary.
- Security: a functional test that `/_profiler` is 403 for a non-admin and reachable for an admin (route loaded in the test/prod-like env).

**Frontend**
- `ProfilingToggle` renders only for `ROLE_ADMIN`; reflects active/inactive + remaining time; posts to enable/disable; hidden for non-admins.

## Rollout / deployment notes

- The prod container self-migrates on start; this feature needs **no DB migration** (session-based flag).
- After deploy, `composer install --no-dev` must now include the two bundles (they moved to `require`) — verify the prod image builds and the profiler stays dormant (`collect: false`) until enabled.
- CI builds but does not run the prod entrypoint — validate the prod profiler config (dormant by default, routes gated) locally against an `APP_ENV=prod` build before shipping.

## Resolved decisions

- Viewing surface: **full web-debug-toolbar + `/_profiler`** (not a custom export).
- Time-box: **30 minutes**, auto-expire + manual off.
- Toggle location: **Settings page**, admin-only section.
- Residual risks (above) **accepted**, including removing the secrets/config/dump collectors.

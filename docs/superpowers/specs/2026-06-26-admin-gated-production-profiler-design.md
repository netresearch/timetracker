# Admin-gated production profiler (two-image pipeline) — design

- **Date:** 2026-06-26
- **Status:** Approved (design); implementation plan pending
- **Author:** Sebastian Mendel

## Goal

Let an **admin use the full Symfony web profiler (web-debug-toolbar + `/_profiler`) against the production server** to capture real-world profiling data for issue reports — without the default production image ever carrying profiler code.

Achieved by building a **second, dedicated "profiling" image** alongside the normal production image. The production server runs `:production` by default; when profiling is needed, an operator **switches the running image to `:profiling`**, captures data, then switches back. Inside the profiling image the profiler is **only ever exposed to admins**.

Motivation: profiling problems needs real production data; non-developer admins currently cannot capture it and so cannot provide detailed issue reports (e.g. the slow first load of `/ui/admin` Customers/Projects). The image swap is a deliberate, auditable infrastructure action — not a runtime flag a bug could flip.

## Non-goals (YAGNI)

- No in-app enable/disable toggle, no per-session time-box, no profiling endpoints, no frontend control. The image swap is the activation; ROLE_ADMIN is the in-image gate.
- No profiler code in the default production image.
- No web UI to run `bin/console` commands.
- No password re-auth or IP-pinning.
- No profiling for non-admins (never collected, toolbar never injected, `/_profiler` 403).

## Approach

**Two images from one pipeline.**

- **`:production`** — unchanged. `APP_ENV=prod`, `composer install --no-dev`. No profiler bundles. This stays the default deployed image.
- **`:profiling`** — new. **Prod-like** (`APP_ENV=profiling`, debug OFF, optimized autoloader, prod cache warmup) so measured timings are representative — *plus* the profiler bundles installed and the Symfony profiler enabled, gated to admins. Built and pushed by CI but **never deployed by default**; an operator switches to it on demand.

## Pipeline changes

Current build (`docker-bake.hcl` + `.github/workflows/docker-publish.yml`): target `app` → Dockerfile stage `production` (tags `:production`/`:latest`/semver/sha); `app-e2e` → stage `e2e`.

1. **New Dockerfile stage `profiling`.** Prod-like, like `production` (optimized autoloader, `APP_DEBUG=0`, prod cache warmup) but:
   - Installs the profiler bundles (does **not** pass `--no-dev`, or installs `web-profiler-bundle` + `debug-bundle` + `stopwatch` explicitly), keeping an optimized autoloader.
   - Sets `ENV APP_ENV=profiling`.
   - (Exact stage structuring — `FROM base` mirroring `production` vs `FROM production` + add deps + re-warm cache — decided in the implementation plan; the autoloader-optimization and prod cache-warmup must be preserved.)
2. **New bake target `app-profiling`** in `docker-bake.hcl` → stage `profiling`, tag `${REGISTRY}/${IMAGE_NAME}:profiling` (and optionally `:profiling-${GIT_SHA}` for an immutable pin), added to the `all` / a `ci` group.
3. **CI push step** in `docker-publish.yml`: a `docker/bake-action` step with `targets: app-profiling`, pushed on the default branch only (mirrors the production/e2e steps). The image goes to GHCR like the others.

## Application changes (small)

All scoped to the `profiling` env; the `prod` env is untouched.

1. **Bundles per env.** `config/bundles.php`: enable `WebProfilerBundle` and `DebugBundle` for `profiling` (currently `['dev' => true, 'test' => true]`). Composer keeps the bundles in `require-dev`; only the profiling image installs dev deps.
2. **Profiler config** `config/packages/profiling/web_profiler.yaml`:
   - `framework.profiler: { enabled: true, collect: true }`.
   - `web_profiler: { toolbar: true, intercept_redirects: false }`.
3. **Collect only for admins** — `CollectForAdminsOnlyListener` (`kernel.request`, after the firewall sets the token): if the authenticated user is **not** `ROLE_ADMIN`, call `Profiler::disable()` for the request. So non-admins are never collected and never get the toolbar; the (unauthenticated) login route is naturally skipped (no admin token yet). Admins get full collection + toolbar on HTML pages, and every SPA XHR gets an `X-Debug-Token` viewable in `/_profiler/{token}?panel=db`.
4. **Route lock.** Load the profiler routes in the profiling env (`config/routes/profiling/web_profiler.yaml`, mirroring the dev one for `/_wdt` + `/_profiler`). In `config/packages/security.yaml`, add **above** the `^/ → IS_AUTHENTICATED_FULLY` catch-all: `{ path: ^/_(profiler|wdt), roles: ROLE_ADMIN }`. (Harmless in `prod`/`dev` — those paths don't exist in `prod`, and `dev` has its own firewall.)
5. **Make the DB panel work.** `doctrine.yaml` sets `dbal.profiling: '%kernel.debug%'` (false when debug is off). In `config/packages/profiling/doctrine.yaml` set `dbal.profiling: true` (with `profiling_collect_backtrace: false` to bound memory) so the query list, timings, and `EXPLAIN` populate — the whole point for perf work.
6. **Trim sensitive panels.** A `CompilerPass` (registered only in the profiling env) removes the **dump** (`VarDumper`) and **config** (`ConfigDataCollector`) data collectors so their panels are absent. Keep DB/Time/Memory/Request/Routing/Events/Logs/Cache. Exact collector service IDs confirmed during implementation.
7. **Time panel caveat.** The Time panel needs `symfony/stopwatch`, normally wired under `kernel.debug`. Verify it populates in the `profiling` env (debug off); pull `symfony/stopwatch` into the installed set if the timeline is wanted.
8. **(Optional) status hint.** Surface "running profiling image" on the existing `/ui/admin` status surface (which already shows git ref / build date), so admins can tell at a glance they're on the profiling build. Low effort, reuses an existing panel.

## Components (units)

| Unit | Responsibility | Interface | Depends on |
|------|----------------|-----------|------------|
| Dockerfile `profiling` stage | Prod-like image + profiler bundles, `APP_ENV=profiling` | build stage | `base`/`production` |
| `app-profiling` bake target | Build/tag `:profiling` | bake target | Dockerfile stage |
| CI push step | Build+push `:profiling` on default branch | workflow step | bake target |
| `config/packages/profiling/*` | Enable profiler, toolbar, doctrine profiling | config | bundles |
| `config/bundles.php` | Enable WebProfiler/Debug bundles for `profiling` | config | — |
| `config/routes/profiling/web_profiler.yaml` | Load `/_wdt` + `/_profiler` | routes | bundle |
| `security.yaml` access_control | ROLE_ADMIN-lock `/_(profiler|wdt)` | config | — |
| `CollectForAdminsOnlyListener` | Disable profiler for non-admins | `kernel.request` subscriber | `Profiler`, security token |
| `RemoveSensitiveCollectorsPass` | Drop dump/config collectors in profiling env | compiler pass | container |

## Security model (explicitly accepted)

- The default production image contains **no profiler code** — zero added surface in normal operation.
- The profiling image is a **deliberate, temporary, operator-initiated** switch. While running it:
  - Non-admins: never collected, no toolbar, `/_profiler` → 403.
  - Admins: full toolbar + `/_profiler`.
- **Accepted residual risks (only while the profiling image is deployed):**
  1. Profiler panels can reveal server/env data and request bodies to any `ROLE_ADMIN`. Mitigated by removing the dump/config collectors; the rest is accepted within the ROLE_ADMIN trust boundary.
  2. One admin can view another admin's collected profile (listed by token). Accepted within ROLE_ADMIN trust.
  3. Stored profiles hold sensitive request data → bounded by the image being temporary; profiles live in the container's cache dir and vanish when it's swapped back.
  4. Larger attack surface; an admin session hijack also reaches the profiler. Bounded by the temporary, deliberate switch.

## Operations / rollout

- **Default unchanged:** prod runs `:production`. CI also publishes `:profiling`, which is **never auto-deployed**.
- **To profile:** switch the running container's image tag to `:profiling` (compose image override / hot-deploy), reproduce the issue as an admin, read the toolbar / `/_profiler`, then **switch back to `:production`**.
- Document the switch + switch-back in the deploy runbook; treat `:profiling` as never-the-default.
- No DB migration. CI builds but does not run the prod entrypoint — validate the `profiling` image locally (admin sees toolbar, non-admin gets 403, perf is prod-representative) before relying on it.

## Resolved decisions

- Delivery: **two images** (`:production` unchanged, new `:profiling`), switch on the server. Composer bundles stay `require-dev`; only the profiling image installs them.
- Profiling env: **prod-like `APP_ENV=profiling`** (debug off, prod caches) for representative timings.
- In-image activation: **always-on for admins** — no toggle/time-box; ROLE_ADMIN is the in-image gate, the image swap is the activation.
- Viewing surface: **full web-debug-toolbar + `/_profiler`**.
- Sensitive collectors (dump/config) **removed**; residual risks **accepted**.

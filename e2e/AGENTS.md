<!-- Managed by agent: keep sections and order; edit content, not structure. -->

# AGENTS.md — e2e/

## Overview

Playwright end-to-end suite running against a dedicated Docker stack
(`app-e2e`, `httpd-e2e`, `db-e2e`, `ldap-dev`) on http://localhost:8766.
Node 26 (`.nvmrc`); the Playwright tooling is the only npm usage at the repo root.

## Setup & environment

- Start stack + run suite + stop stack: `make e2e`
- Start stack only: `make e2e-up` (db-e2e seeds itself from `sql/full.sql` +
  `sql/testdata.sql` on first start; the target waits until /login responds)
- Run against a running stack: `make e2e-run` (or `npm run e2e`)
- Stop stack: `make e2e-down`
- Install browsers once: `make e2e-install`
- Base URL override: `E2E_BASE_URL` (defaults to http://localhost:8766)
- `db-e2e` is a PERSISTENT volume seeded only once (first start) from
  `sql/full.sql` + `sql/testdata.sql` — after any entity gains a column,
  re-seed manually: `DROP DATABASE`+`CREATE` the `timetracker` DB first (must
  drop+create before reloading — loading over an existing schema with FKs
  loads only partially and silently), reload BOTH SQL files, then
  `cache:clear`. Query/administer via
  `docker compose exec -T db-e2e mariadb -uroot -pglobal123 timetracker`

## Rebuilding after code changes

- `app-e2e` (and `app`) bind-mounts the host `./public` AND the whole repo over
  the container, so rebuilding the image changes nothing the browser sees.
  After editing `frontend/src/*` run `cd frontend && bun run build` (the
  `build` script lives ONLY in `frontend/package.json` — from repo root it
  fails `Script not found`) to refresh host `public/build-ui`; for PHP/Twig
  edits just `docker exec … php bin/console cache:clear`. Verify with
  `grep -roh '<string>' public/build-ui/assets/*.js` before screenshotting. Do
  NOT `make e2e-up`/`docker bake --no-cache` for a code change. Review/production
  images copy assets in (no bind mount) and DO need a rebuild
- Local `/ui` e2e diverges from CI: worklog-grid/admin relation comboboxes
  render empty locally even after rebuild+reseed, so
  `worklog-crud`/`worklog-grid-editing`/`session-expiry`/`admin-inline-edit`
  must be validated in CI — CI is authoritative for those

## Test data & clock

- Server time is FROZEN via `APP_FROZEN_TIME="2024-01-15 12:00:00"`
  (compose.yml, app-e2e) to match the seed data in `sql/testdata.sql`
- Browser-side clock freezing: use the helpers in `e2e/helpers/clock.ts`
- Test users (`e2e/helpers/auth.ts`): only `developer`/`dev123` (DEV) and
  `i.myself`/`myself123` (PL — sees Billing/Administration) actually work
  end-to-end — both exist in db-e2e AND LDAP and can book the one bookable
  customer (`Freizeit`, id 2). `unittest`/`test123` is in LDAP but ABSENT from
  db-e2e, so its login 302s back to `/login` — don't rely on it. Passwords can
  be overridden via `E2E_*_PASSWORD` env vars
- Backend PHP integration tests use a DIFFERENT clock:
  `Tests\Service\TestClock` (hard-coded 2023-10-24) IGNORES `APP_FROZEN_TIME`;
  its fixtures (`sql/full.sql`, user `unittest`) are aligned to that date
  instead. Don't "fix" `TestClock` to honor `APP_FROZEN_TIME` — it reds
  `test-integration` (e.g. `BulkEntryVisibilityTest`); aligning clock+seed is a
  deferred multi-surface change

## Build & tests (prefer file-scoped)

- Single spec: `npx playwright test e2e/worklog.spec.ts`
- Headed/debug: `npm run e2e:headed` / `npm run e2e:debug` (see package.json)

## Code style & conventions

- Shared logic goes into `e2e/helpers/` (auth, navigation, grid, worklog, api)
- Use role/label-based locators; the app is built to be accessible — prefer
  `getByRole`/`getByLabel` over CSS selectors
- A web-first assertion gating on a save→refetch→SolidJS-reconcile→render
  round-trip needs `{ timeout: 15000 }`, not the Playwright default 5s, under
  CI contention
- Local full-suite runs can flake on keyboard-interaction specs on a loaded
  box, while CI shards stay green (~16 tests/runner, `retries: 2`) — re-run the
  single spec with `--workers=2 --retries=2` rather than treating a
  full-suite-on-one-machine failure as a CI blocker

## Security & safety

- Never point the suite at a non-test instance: specs create and delete data
- `.env.test.local` is generated from `.env.test.local.example` by `make e2e-up`
  and is gitignored — don't commit it

## PR/commit checklist

- [ ] New user flows covered by a spec or an extension of an existing one
- [ ] Specs pass locally: `make e2e`
- [ ] No hardcoded waits — use Playwright auto-waiting or `waitFor`

## When stuck

- The test env renders GERMAN by default (`config/packages/test/translation.yaml`
  falls back to `de`) — assert on German strings or on structure, not English text
- Root [`AGENTS.md`](../AGENTS.md) for repo-wide rules

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

## Test data & clock

- Server time is FROZEN via `APP_FROZEN_TIME="2024-01-15 12:00:00"`
  (compose.yml, app-e2e) to match the seed data in `sql/testdata.sql`
- Browser-side clock freezing: use the helpers in `e2e/helpers/clock.ts`
- Test users (`e2e/helpers/auth.ts`): `developer`/`dev123`,
  `unittest`/`test123`, `i.myself`/`myself123` (PL — sees Billing/Administration);
  passwords can be overridden via `E2E_*_PASSWORD` env vars

## Build & tests (prefer file-scoped)

- Single spec: `npx playwright test e2e/worklog.spec.ts`
- Headed/debug: `npm run e2e:headed` / `npm run e2e:debug` (see package.json)

## Code style & conventions

- Shared logic goes into `e2e/helpers/` (auth, navigation, grid, worklog, api)
- Use role/label-based locators; the app is built to be accessible — prefer
  `getByRole`/`getByLabel` over CSS selectors

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

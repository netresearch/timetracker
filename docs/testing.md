# Testing Guide

How TimeTracker is tested: PHPUnit suites, the Playwright e2e stack, frontend
Vitest, and the CI pipeline. Everything below is verified against
[phpunit.xml.dist](../phpunit.xml.dist), [composer.json](../composer.json),
[Makefile](../Makefile), [compose.yml](../compose.yml) and
[.github/workflows/ci.yml](../.github/workflows/ci.yml).

## Overview

| Layer | Tool | Where | Run with |
|-------|------|-------|----------|
| Backend unit | PHPUnit 13 | [tests/](../tests/) (suite `unit`) | `make test-unit` |
| Backend integration | PHPUnit 13 + MariaDB | suites `integration`, `controller`, `api-functional` | `make test-integration` etc. |
| Backend performance | PHPUnit 13 | [tests/Performance/](../tests/Performance/) | `composer perf:*` |
| Architecture rules | PHPat (PHPStan pass) | [tests/Architecture/](../tests/Architecture/) | `make phpat` |
| Frontend unit | Vitest (jsdom) | [frontend/src/](../frontend/src/) | `cd frontend && bun run test` |
| End-to-end | Playwright | [e2e/](../e2e/) | `make e2e` |

Static analysis (PHPStan **level 10**, [phpstan.neon](../phpstan.neon)) and
code style gates are covered in [development.md](development.md#code-quality).

Note: Composer's `bin-dir` is `bin/` — the PHPUnit binary is `./bin/phpunit`,
not `vendor/bin/phpunit`.

## PHPUnit suites

[phpunit.xml.dist](../phpunit.xml.dist) defines four suites:

| Suite | Contents | Database |
|-------|----------|----------|
| `unit` | Pure unit tests: DTOs, events, helpers, services, validators, value objects, entity/enum/exception tests, selected repository and security tests | no |
| `integration` | `*DatabaseTest` entity tests, repository tests, command tests, LDAP client integration | yes |
| `controller` | HTTP tests in [tests/Controller/](../tests/Controller/) | yes |
| `api-functional` | CRUD + response-format checks in [tests/Api/Functional/](../tests/Api/Functional/) | yes |

Performance suites (`performance`, `performance-unit`,
`performance-integration`) live in a separate config,
[config/testing/phpunit-performance.xml](../config/testing/phpunit-performance.xml).

A local `phpunit.xml` (gitignored) can override the dist config — see
[DEVELOPER_PHPUNIT_CUSTOMIZATION.md](DEVELOPER_PHPUNIT_CUSTOMIZATION.md).

### Make targets (run in Docker, recommended)

```bash
make test                # all suites against db_unittest
make test-unit           # unit suite, no database, fast
make test-integration    # integration suite
make test-controller     # controller suite
make test-api-functional # api-functional suite
make test-quick          # alias for test-unit (pre-commit speed)
make test-verbose        # verbose config (config/testing/phpunit.xml.verbose)
make test-debug          # with XDEBUG_MODE=debug,develop for step debugging
make coverage            # HTML coverage → var/coverage/index.html
make test-all            # PHPUnit + Playwright e2e
```

### Composer scripts (run inside a container)

Defined in [composer.json](../composer.json):

```bash
composer test               # all suites
composer test:unit          # unit suite
composer test:controller    # controller suite
composer test:fast          # unit + controller, without result cache
composer test:coverage      # HTML coverage → var/coverage
composer test:coverage-text # text coverage summary
composer perf:export        # performance suite (exports)
composer perf:unit          # performance-unit suite
composer perf:integration   # performance-integration suite
composer perf:benchmark     # tests/Performance/PerformanceBenchmarkRunner.php
composer perf:dashboard     # HTML dashboard → var/performance-dashboard.html
```

### Test database

Database-backed suites use the dedicated **`db_unittest`** container
(MariaDB 12.1, no published host port). The connection is
`mysql://unittest:unittest@db_unittest:3306/unittest` ([.env.test](../.env.test)).

Seeding: `make prepare-test-sql` (run automatically by the test targets)
generates `sql/unittest/001_testtables.sql` from [sql/full.sql](../sql/full.sql);
the container loads it plus `sql/unittest/002_testdata.sql` on first start.
After schema changes, rebuild it:

```bash
make reset-test-db   # drop volume + reseed db_unittest from sql/full.sql
```

## Playwright e2e tests

The e2e suite lives in [e2e/](../e2e/) (specs: `login`, `navigation`,
`worklog`, `worklog-crud`, `worklog-grid-editing`, `interpretation`, `export`,
`settings`, `date-format`, `error-handling`, `session-expiry`,
`accessibility` (with `@axe-core/playwright`), `admin-inline-edit`,
`admin/admin-ui`; shared helpers in [e2e/helpers/](../e2e/helpers/)).

It runs against a dedicated compose stack (profile `e2e`, port **8766**):
`app-e2e` + `httpd-e2e` + `db-e2e` + `ldap-dev`. Key properties
([compose.yml](../compose.yml)):

- **Frozen clock**: `APP_FROZEN_TIME=2024-01-15 12:00:00` — matches the data in
  [sql/testdata.sql](../sql/testdata.sql), so date-dependent tests are
  deterministic ([src/Service/ClockFactory.php](../src/Service/ClockFactory.php)).
- **Separate database** (`db-e2e`) so dev data is untouched.
- **LDAP logins** from [docker/ldap/users-only.ldif](../docker/ldap/users-only.ldif)
  (`developer`/`dev123` etc., see [e2e/helpers/auth.ts](../e2e/helpers/auth.ts)).
- `make e2e-up` copies `.env.test.local.example` to `.env.test.local` if missing.

```bash
make e2e          # start stack, run tests, tear down
make e2e-up       # start the e2e stack on http://localhost:8766
make e2e-run      # run tests against the running stack
make e2e-down     # stop the e2e stack
make e2e-install  # npx playwright install chromium
```

Direct Playwright invocations (root [package.json](../package.json), Node 26):

```bash
npm run e2e            # playwright test
npm run e2e:headed     # with browser window
npm run e2e:ui         # Playwright UI mode
npm run e2e:debug      # step debugger
npm run screenshots -- --route /ui/tracking --out docs/images/pr-574 --name worklog
```

`E2E_BASE_URL` overrides the target (default `http://localhost:8766`,
[playwright.config.ts](../playwright.config.ts)); outside CI, Playwright
starts the stack itself via `make e2e-up` if it is not already running.

The screenshot helper in [e2e/tools/capture-screenshots.mjs](../e2e/tools/capture-screenshots.mjs)
logs in with the E2E defaults, waits for the target UI selector, and captures
desktop/reduced viewport PNGs. Use `npm run screenshots -- --help` for routes,
credentials, selectors, output names, and viewport overrides.

Backend coverage during e2e runs is optional: start the stack with
`COVERAGE_ENABLED=1 XDEBUG_MODE=coverage` and fetch the report from
[public/coverage.php](../public/coverage.php) (CI does this per shard).

## Frontend tests (Vitest)

```bash
cd frontend
bun run test        # vitest run (jsdom, @solidjs/testing-library, vitest-axe)
bun run test:watch  # watch mode
```

See [frontend/README.md](../frontend/README.md).

## Continuous integration

CI is authoritative for test results. Workflows in
[.github/workflows/](../.github/workflows/):

- **[ci.yml](../.github/workflows/ci.yml)** — the main pipeline on pushes and PRs:
  - `setup`: install composer/npm deps, build the Vite UI, share as artifact
  - `frontend`: bun — ESLint, tsc, Vitest, Vite build
  - `lint`: composer validate + audit, PHPStan, PHPat, PHP-CS-Fixer, Rector
    (dry-run), Twig lint
  - `test-unit`: PHPUnit `unit` suite with coverage → Codecov (flag `unit`)
  - `test-integration`: `integration,controller,api-functional` suites against
    `db_unittest` → Codecov (flag `integration`)
  - `e2e`: Playwright in **10 shards** against the e2e stack, backend coverage
    via `coverage.php` → Codecov (flag `e2e`)
  - `ci-success`: aggregate gate
- **[security.yml](../.github/workflows/security.yml)** — npm dependency audit
- **[codeql.yml](../.github/workflows/codeql.yml)** — CodeQL (JavaScript/TypeScript)
- **[docker-publish.yml](../.github/workflows/docker-publish.yml)** — image builds/publishing
- **[scorecard.yml](../.github/workflows/scorecard.yml)**, **[slsa-provenance.yml](../.github/workflows/slsa-provenance.yml)** — supply-chain checks
- **[auto-merge-deps.yml](../.github/workflows/auto-merge-deps.yml)** — dependency PR automation

Coverage reporting is configured in [codecov.yml](../codecov.yml).

## Pre-commit hooks

CaptainHook runs PHP lint, PHPStan, PHP-CS-Fixer, PHPat and the PHPUnit `unit`
suite on staged PHP files before every commit — see
[development.md](development.md#git-hooks-captainhook).

## Writing tests

- Test classes live under `tests/`, namespace `Tests\`
  ([composer.json](../composer.json) `autoload-dev`), bootstrapped by
  [tests/bootstrap.php](../tests/bootstrap.php).
- Web tests extend [tests/AbstractWebTestCase.php](../tests/AbstractWebTestCase.php).
- New DB-free tests should be added to the `unit` suite in
  [phpunit.xml.dist](../phpunit.xml.dist) (directories are whitelisted
  explicitly).
- Run a single test:

```bash
docker compose run --rm -e APP_ENV=test app-dev \
  ./bin/phpunit --filter=testMethodName tests/Path/To/SomeTest.php
```

More conventions: [tests/README.md](../tests/README.md).

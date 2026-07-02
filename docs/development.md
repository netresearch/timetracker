# Development Guide

How to set up and work on TimeTracker locally. Everything runs in Docker —
no local PHP installation is needed. See [techstack.md](techstack.md) for the
technology overview.

## Prerequisites

| Tool | Version | Purpose |
|------|---------|---------|
| Docker + Compose v2 | recent | All services run in containers ([compose.yml](../compose.yml)); images are built with `docker bake` ([docker-bake.hcl](../docker-bake.hcl)) |
| GNU make | any | Task runner ([Makefile](../Makefile)) |
| Node.js | 26 ([.nvmrc](../.nvmrc)) | Only for running the Playwright e2e suite from the host |

The application requires PHP 8.5 ([composer.json](../composer.json)) — provided
by the Docker images, so you do not need it on the host. The frontend uses
[bun](https://bun.sh/); `make npm-build` runs it inside the container, so a host
install is optional.

## Quick start

```bash
git clone https://github.com/netresearch/timetracker.git
cd timetracker

make up          # build dev image + start the stack (compose profile: dev)
make install     # composer install + npm install (inside the app-dev container)
make npm-build   # build the SolidJS UI into public/build-ui
make db-migrate  # apply Doctrine migrations
```

The app is now at **http://localhost:8765** (port configurable via `HTTP_PORT`,
see [configuration.md](configuration.md)).

On the first start, the database container seeds itself from
[`sql/full.sql`](../sql/full.sql) (schema) and
[`sql/testdata.sql`](../sql/testdata.sql) (deterministic test data pinned to
2024-01-15) — there is no fixtures bundle.

### Dev login (local LDAP)

The dev stack includes an OpenLDAP container (`ldap-dev`, host port 3389) with
users from [`docker/ldap/users-only.ldif`](../docker/ldap/users-only.ldif):

| Username | Password | Note |
|----------|----------|------|
| `i.myself` | `myself123` | Seeded in the DB as type `PL` → has admin rights |
| `developer` | `dev123` | Created on first login (`LDAP_CREATE_USER=true`) |
| `unittest` | `test123` | Created on first login |
| `admin` | `admin123` | Created on first login |

## Daily commands

```bash
make help        # list all targets
make up / down   # start / stop the stack
make logs        # follow container logs
make sh          # bash inside the app-dev container
make cache-clear # bin/console cache:clear
```

Symfony console commands run inside the container, e.g.
`docker compose exec app-dev bin/console debug:router`. The app defines two own
commands: `tt:encrypt-jira-tokens` and `tt:sync-subtickets`
([src/Command/](../src/Command/)).

A [Swagger UI](../public/docs/swagger/) is served at
http://localhost:8765/docs/swagger/index.html (`make swagger` prints the URL).

## Frontend workflow

The UI is a SolidJS SPA in [`frontend/`](../frontend/) — see
[frontend/README.md](../frontend/README.md) for details.

```bash
cd frontend
bun install
bun run dev        # Vite dev server with HMR (next to the running Symfony app)
bun run build      # production build into ../public/build-ui
bun run lint       # ESLint
bun run typecheck  # paraglide compile + tsc --noEmit
bun run test       # Vitest (jsdom)
```

Containerized equivalents: `make npm-build`, `make npm-dev`.

## Testing

See [testing.md](testing.md) for the full picture. The short version:

```bash
make test          # all PHPUnit tests against the db_unittest container
make test-unit     # unit suite only (no database, fast)
make e2e           # Playwright e2e suite (starts its own stack on port 8766)
make coverage      # PHPUnit with HTML coverage → var/coverage/index.html
```

Note: Composer's `bin-dir` is `bin/`, so the PHPUnit binary is
`./bin/phpunit` — there is no `vendor/bin/`.

## Code quality

All quality tools run in the lightweight `app-tools` container (no database):

```bash
make check-all   # PHPStan + PHPat + PHP-CS-Fixer check + Twig lint
make fix-all     # PHP-CS-Fixer fix + Rector apply

make stan        # PHPStan, level 10 (phpstan.neon)
make phpat       # architecture rules (config/quality/phpat.neon)
make cs-check    # PHP-CS-Fixer dry-run
make cs-fix      # PHP-CS-Fixer apply
make rector      # Rector dry-run (config/quality/rector.php)
make rector-fix  # Rector apply
make twig-lint   # lint:twig templates
make audit       # composer audit
```

The underlying composer scripts (`composer analyze`, `analyze:arch`,
`cs-check`, `cs-fix`, `rector`, `twig:lint`, `check:all`, `fix:all`) are
defined in [composer.json](../composer.json) and can also be run inside any
app container.

## Git hooks (CaptainHook)

Git hooks are managed by [CaptainHook](https://github.com/captainhookphp/captainhook)
and installed automatically by the `captainhook/plugin-composer` plugin during
`composer install`. [captainhook.json](../captainhook.json) configures:

- **pre-commit** (conditional on staged file types):
  - `composer validate` + `composer audit` when `composer.json`/`composer.lock` are staged
  - PHP syntax check, PHPStan, PHP-CS-Fixer (dry-run), PHPat, and the PHPUnit
    `unit` suite when PHP files are staged
  - `lint:twig` when Twig files are staged
- **commit-msg**: enforces
  [Conventional Commits](https://www.conventionalcommits.org/)
  (`type(scope): description`)

## Debugging

Xdebug is installed in the dev image but **off by default** (no runtime
overhead). Enable it per run via the `XDEBUG_MODE` environment variable —
e.g. `make test-debug` for step-debugging tests. Full IDE setup:
[xdebug-setup.md](xdebug-setup.md).

The Symfony profiler and debug toolbar are available in the dev environment
(`APP_ENV=dev`, default for the `app-dev` service).

## Environment configuration

Symfony reads `.env` → `.env.local` → `.env.$APP_ENV` → `.env.$APP_ENV.local`
(later files win, real environment variables win over all). Docker Compose
interpolates its own settings (profiles, ports, DB credentials) from the shell
environment or the `.env` file; [.env.example](../.env.example) documents them.
All variables are described in [configuration.md](configuration.md).

## Troubleshooting

- **Composer platform error (PHP 8.5)**: `make composer-install` passes
  `--ignore-platform-req=php` until `laminas/laminas-ldap` declares PHP 8.5
  support ([laminas-ldap#62](https://github.com/laminas/laminas-ldap/issues/62)).
- **Schema changed, tests fail**: `make reset-test-db` recreates the
  `db_unittest` container and volume from `sql/full.sql`.
- **Out-of-memory in tests**: the dev container mounts
  [`docker/php/test.ini`](../docker/php/test.ini) (2G memory limit); for ad-hoc
  runs use `php -d memory_limit=2G ./bin/phpunit`.
- **Port 8765 already in use**: set `HTTP_PORT` in `.env` (compose level).

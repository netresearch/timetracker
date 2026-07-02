# Deployment Guide

How to run the Netresearch TimeTracker in production using the published
container image and the repository's Docker Compose setup.

Everything in this guide is derived from the files it links to — primarily
[`compose.yml`](../compose.yml), [`docker-bake.hcl`](../docker-bake.hcl),
[`Dockerfile`](../Dockerfile) and [`.env.example`](../.env.example).

## Architecture

The production stack (Compose profile `prod`) consists of three containers:

| Service | Image | Role |
|---------|-------|------|
| `httpd` | `nginx:1.28-alpine` | Web server; serves static assets, forwards PHP to `app` via FastCGI. Publishes port `${HTTP_PORT:-8765}`. |
| `app`   | `ghcr.io/netresearch/timetracker:production` | PHP-FPM 8.5 running the Symfony application (non-root user, listens on 9000 inside the network). |
| `db`    | `mariadb:12.1` | Database; schema seeded from [`sql/full.sql`](../sql/full.sql) on first start. |

Named volumes: `app-pub` (built assets, shared between `app` and `httpd`),
`app-cache`, `app-logs`, `db-data`.

The application serves plain HTTP. TLS termination is expected at a reverse
proxy in front of the stack (see [Reverse proxy and TLS](#reverse-proxy-and-tls)).

## Container images

Images are built with `docker bake` ([`docker-bake.hcl`](../docker-bake.hcl) is
the single source of truth for base images and versions: `php:8.5-fpm`,
Node 26, `composer:2.10`, pinned APCu/Xdebug) and published to GHCR by
[`.github/workflows/docker-publish.yml`](../.github/workflows/docker-publish.yml):

| Tag | Built from | Purpose |
|-----|------------|---------|
| `production`, `latest` | `main` branch | Production deployments |
| `X.Y.Z`, `X.Y`, `X` | git tags `vX.Y.Z` | Version-pinned production deployments |
| `sha-<commit>`, branch names | every push | Reproducible/preview deployments |
| `e2e`, `e2e-<sha>` | every push | CI test image (Playwright, Xdebug) |
| `profiling`, `profiling-<sha>` | every push | Prod-like image with the admin-gated Symfony profiler (see below) |

The `dev` and `tools` images are for local development only and are built
locally (`make bake-dev`, `make bake-tools`).

The production image:

- runs as non-root user `app` (UID 1000); application code is read-only, only
  `var/` is writable,
- has a Docker `HEALTHCHECK` ([`docker/php/healthcheck.sh`](../docker/php/healthcheck.sh):
  php-fpm process + config check),
- applies pending database migrations on start via its entrypoint
  ([`docker/php/docker-entrypoint.sh`](../docker/php/docker-entrypoint.sh)),
- carries build provenance (`APP_BUILD_REVISION`/`APP_BUILD_REF`/`APP_BUILD_DATE`),
  shown read-only on the admin status page `/ui/admin/status`.

## Quick start

```bash
git clone https://github.com/netresearch/timetracker.git
cd timetracker
cp .env.example .env
# Edit .env: set COMPOSE_PROFILES=prod, APP_ENV=prod, APP_DEBUG=0,
# a strong APP_SECRET, DB passwords and your LDAP_* settings (see below)

docker compose --profile prod up -d
```

The app is then reachable on `http://localhost:8765` (or `HTTP_PORT`).

> **Note — nginx upstream name:** the shipped
> [`docker/nginx/default.conf`](../docker/nginx/default.conf) forwards PHP
> requests to `phpfpm:9000`; `compose.yml` gives the `app` service a matching
> `phpfpm` network alias, so no override is needed.

> **Note — test data:** `compose.yml` mounts both `sql/full.sql` (schema) and
> `sql/testdata.sql` (deterministic test data for dev/e2e) into the `db`
> init directory. For a clean production database, remove the `testdata.sql`
> mount in a compose override before the first start.

## Environment variables

The production image ships an **empty `.env`** — all runtime configuration
reaches the `app` container as real environment variables, which `compose.yml`
passes through. Compose substitutes them from the shell environment and from
the `.env` file in the *Compose project directory* (usually this repository
checkout; a different file can be given with `--env-file`), with the shell
taking precedence. Export the variables you need before `docker compose up`,
or set them in a compose override. The relevant ones:

### Application

| Variable | Default | Purpose |
|----------|---------|---------|
| `APP_ENV` / `APP_DEBUG` | `prod` / `0` (baked into the image) | Override only via a compose override file |
| `APP_SECRET` | **required** — `docker compose up` fails fast when unset (the repository `.env` supplies an insecure dev placeholder; replace it) | Symfony secret (CSRF, remember-me). Generate: `openssl rand -base64 32` |
| `APP_ENCRYPTION_KEY` | falls back to `APP_SECRET` | Dedicated key for Jira OAuth token encryption at rest |
| `DATABASE_URL` | **required** — `docker compose up` fails fast when unset (the repository `.env` supplies a value matching the bundled `db` service) | Doctrine DBAL connection, e.g. `mysql://user:pass@db:3306/timetracker?serverVersion=mariadb-12.1.2&charset=utf8mb4` |
| `SENTRY_DSN` | empty | Optional error tracking |
| `APP_LOCALE` | `en` | Instance default locale (users pick their own in Settings) |
| `APP_TITLE`, `APP_LOGO_URL`, `APP_HEADER_URL` | see `.env` | Branding |
| `APP_SHOW_BILLABLE_FIELD_IN_EXPORT` | `false` | Adds the billable column to exports |

### LDAP authentication

| Variable | Example | Purpose |
|----------|---------|---------|
| `LDAP_HOST` | `ldap.example.com` | LDAP/AD server |
| `LDAP_PORT` | `389` | `636` for LDAPS |
| `LDAP_USESSL` | `false` | Set `true` in production |
| `LDAP_READUSER` / `LDAP_READPASS` | `cn=readonly,…` | Read-only bind user for the user search |
| `LDAP_BASEDN` | `dc=example,dc=com` | Search base |
| `LDAP_USERNAMEFIELD` | `uid` | Use `sAMAccountName` for Active Directory |
| `LDAP_CREATE_USER` | `true` | Auto-create users on first successful LDAP login |

### Container / Compose

| Variable | Default | Purpose |
|----------|---------|---------|
| `COMPOSE_PROFILES` | `dev` | Set `prod` for the production stack |
| `HTTP_PORT` | `8765` | Published web port |
| `DB_ROOT_PASSWORD`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | see `.env.example` | Passed to the `db` container; must match `DATABASE_URL` |
| `TRUSTED_PROXY_LIST` | empty | JSON array of proxy IPs/CIDRs to trust (`X-Forwarded-*`) |
| `TRUSTED_PROXY_ALL` | empty | Non-empty = trust the direct peer (use only behind a controlled proxy) |
| `AUTO_MIGRATE` | `1` | Set `0` to skip automatic migrations on container start |

## Database

### Initial setup

On its first start the `db` container executes [`sql/full.sql`](../sql/full.sql),
which creates the full schema **including** the Doctrine migration-version
records — a fresh install is recognised as already up to date.

### Migrations

Migrations run automatically on container start: the production entrypoint
applies pending Doctrine migrations before PHP-FPM starts, so deploying a new
image over an existing database self-migrates. It is idempotent and fails the
container start loudly if a migration fails, so a bad migration aborts the
deploy instead of serving a half-migrated schema.

- Disable with `AUTO_MIGRATE=0` (e.g. when you apply migrations out-of-band,
  or for read-only replicas).
- A database created **before** migration tracking existed (tables present but
  no `doctrine_migration_versions` rows) is baselined automatically from the
  live schema on first start; only genuinely missing migrations run.

Manual invocation:

```bash
docker compose exec app bin/console doctrine:migrations:status
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
```

### Backup and restore

The database is a regular MariaDB instance in the `db-data` volume:

```bash
# Backup
docker compose exec db mariadb-dump -uroot -p"$DB_ROOT_PASSWORD" timetracker > backup-$(date +%F).sql

# Restore
docker compose exec -T db mariadb -uroot -p"$DB_ROOT_PASSWORD" timetracker < backup-2026-07-02.sql
```

Back up on a schedule (cron on the host, or your backup tooling of choice) and
before every upgrade.

## Reverse proxy and TLS

The stack serves plain HTTP on `HTTP_PORT`. Run it behind any TLS-terminating
reverse proxy (nginx, Traefik, HAProxy, a cloud load balancer):

1. Terminate HTTPS at the proxy and forward to `http://<host>:8765`.
2. Have the proxy set the standard `X-Forwarded-For/-Host/-Proto/-Port` headers.
3. Tell the app which proxies to trust — [`public/index.php`](../public/index.php)
   reads:
   - `TRUSTED_PROXY_LIST='["10.0.0.5"]'` — explicit JSON list of proxy IPs, or
   - `TRUSTED_PROXY_ALL=1` — trust the directly connecting peer (only safe when
     the app port is reachable exclusively by your proxy).

With `X-Forwarded-Proto: https` trusted, Symfony treats requests as secure, so
session and remember-me cookies (both configured `secure: auto`) are marked
`Secure` automatically.

## Health checks

- **Container level:** the image's `HEALTHCHECK` verifies the php-fpm process
  and configuration (`docker inspect --format '{{.State.Health.Status}}' …`).
- **HTTP level:** `GET /status/check` is public and returns
  `{"loginStatus": false}` — usable as a liveness probe for external monitoring:

  ```bash
  curl -fsS http://localhost:8765/status/check
  ```

## Upgrades

```bash
# Back up the database first (see above), then:
docker compose pull app
docker compose up -d
```

The new container applies any pending migrations on start (see above).

> **Note — stale assets:** Docker seeds a named volume from the image only when
> the volume is empty. Because `app-pub` persists `public/` (incl. the built
> SPA in `public/build-ui`) across restarts, a **new image version does not
> refresh it**. After pulling a new image, recreate the volume so it is
> re-seeded:
>
> ```bash
> docker compose --profile prod down
> docker volume rm <project>_app-pub   # e.g. timetracker_app-pub
> docker compose --profile prod up -d
> ```

### Rollback

Deploy a version-pinned tag (e.g. `ghcr.io/netresearch/timetracker:1.2.3`) via
image override and `docker compose up -d`. Migrations are not reverted
automatically — if the newer version migrated the schema, restore the database
backup taken before the upgrade.

## Console commands

The application ships exactly two custom commands
([`src/Command/`](../src/Command)), plus the standard Symfony/Doctrine ones:

```bash
# Encrypt legacy plaintext Jira OAuth tokens at rest (idempotent)
docker compose exec app bin/console tt:encrypt-jira-tokens

# Update project subtickets from Jira (optionally for a single project)
docker compose exec app bin/console tt:sync-subtickets [project-id]

# Standard maintenance
docker compose exec app bin/console cache:clear
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
```

## Production profiling (admin-gated profiler image)

CI publishes `ghcr.io/netresearch/timetracker:profiling` alongside
`:production`. It is prod-like (`APP_ENV=profiling`, debug off, optimized) but
ships the Symfony web profiler, exposed **only to admins** — never the default
deployment. To profile a production issue:

1. `docker pull ghcr.io/netresearch/timetracker:profiling`
2. Switch the `app` service to the `:profiling` tag (same DB and env). It
   self-migrates like `:production`, so the switch is a schema no-op.
3. Reproduce the slow action while logged in as an admin. Full-page loads show
   the web debug toolbar; each SPA/XHR call carries an `X-Debug-Token` — open
   `/_profiler/{token}?panel=db` for queries and timings.
4. Capture what you need, then **switch back to `:production`**.

Security: non-admins are never profiled and `/_profiler` / `/_wdt` return 403
for them (locked to `ROLE_ADMIN` in
[`config/packages/security.yaml`](../config/packages/security.yaml)); collected
profiles live in the container cache and vanish when it is swapped back.

## Production checklist

- [ ] `APP_ENV=prod`, `APP_DEBUG=0`
- [ ] Strong, unique `APP_SECRET` (`openssl rand -base64 32`)
- [ ] `APP_ENCRYPTION_KEY` set (Jira token encryption independent of `APP_SECRET`)
- [ ] Non-default `DB_ROOT_PASSWORD` / `DB_PASSWORD`, matching `DATABASE_URL`
- [ ] `LDAP_USESSL=true` (or LDAPS port 636) for production LDAP
- [ ] HTTPS terminated at a reverse proxy; `TRUSTED_PROXY_LIST` set
- [ ] `sql/testdata.sql` mount removed for the production `db`
- [ ] Database backups scheduled and restore tested
- [ ] Optional: `SENTRY_DSN` for error tracking
- [ ] Optional: pin a version tag instead of `:production` for controlled rollouts

## Related documentation

- [Tech stack](techstack.md) — versions and frameworks
- [Configuration](configuration.md) — application settings
- [APCu setup](apcu-setup.md) — the app cache backend
- [Security](security.md) — authentication and authorization details
- [Troubleshooting](TROUBLESHOOTING.md) — common failure modes

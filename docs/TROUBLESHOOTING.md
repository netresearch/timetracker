# Troubleshooting Guide

Real failure modes and the commands that actually exist to diagnose them.
Default ports: **8765** (dev/prod web), **8766** (e2e web), **3389** (dev LDAP,
host side). Service names come from [`compose.yml`](../compose.yml).

## Quick diagnostics

```bash
# What is running (and its health)?
docker compose ps

# Container logs
docker compose logs -f app-dev     # dev PHP-FPM (app in prod, app-e2e in e2e)
docker compose logs -f httpd-dev   # dev nginx (httpd in prod)
docker compose logs -f db

# Is the app answering? (public endpoint, no login required)
curl -fsS http://localhost:8765/status/check
# -> {"loginStatus":false}

# Database reachable from the app container?
docker compose exec app-dev bin/console dbal:run-sql 'SELECT 1'

# Symfony application logs
tail -f var/log/dev.log            # var/log/prod.log in prod
```

## Stack won't start / port conflicts

The web server binds `${HTTP_PORT:-8765}`, the e2e stack `${E2E_HTTP_PORT:-8766}`,
and the dev LDAP server publishes host port `3389`.

```bash
# Who is holding the port?
lsof -i :8765
lsof -i :8766

# Use another port
HTTP_PORT=9080 docker compose up -d
```

If containers restart in a loop, check `docker compose logs` first — the
production `app` entrypoint exits loudly when the database is unreachable for
more than ~60 s or a migration fails (see
[`docker/php/docker-entrypoint.sh`](../docker/php/docker-entrypoint.sh)).

Rebuild images after Dockerfile/dependency changes (`docker bake`, never
`docker build`):

```bash
make bake-dev && docker compose up -d    # or: make up
```

## Cache permission errors on var/

The dev container runs as root and bind-mounts `./var/cache` and `./var/log`
([`compose.yml`](../compose.yml)), so files created by the container are
root-owned on the host. Typical symptoms: `Failed to remove directory/file
var/cache/...` or `Unable to write to the "cache" directory`.

```bash
# Clear the cache from inside the container (same user that created it)
docker compose run --rm app-dev rm -rf var/cache/dev var/cache/test
make cache-clear                          # bin/console cache:clear in the container

# Or re-own the directories on the host
sudo chown -R "$USER" var/
```

## Database connection failures

Symptoms: `SQLSTATE[HY000] [2002] Connection refused`, or the entrypoint's
`database not reachable after 60s`.

1. Check the `db` container is up and healthy: `docker compose ps db`,
   `docker compose logs db`.
2. Check `DATABASE_URL` — host must be the Compose **service name**:

   ```text
   mysql://timetracker:timetracker@db:3306/timetracker?serverVersion=mariadb-12.1.2
   ```

   PHPUnit uses `db_unittest`, e2e uses `db-e2e` (see the [Makefile](../Makefile)
   and `compose.yml`).
3. Test credentials directly:

   ```bash
   docker compose exec db mariadb -utimetracker -ptimetracker -e 'SELECT 1' timetracker
   ```

4. The DB is seeded from `sql/full.sql` **only when the `db-data` volume is
   empty**. Changed DB credentials in `.env` after the first start do not apply
   to an existing volume — either change them in MariaDB or recreate the volume
   (destroys data): `docker compose down && docker volume rm timetracker_db-data`.

For test-database schema drift after pulling migrations:

```bash
make reset-test-db
```

## LDAP login failures

Login always goes through [`src/Security/LdapAuthenticator.php`](../src/Security/LdapAuthenticator.php).

1. **Check the `LDAP_*` variables** (`.env` / `.env.local`): `LDAP_HOST`,
   `LDAP_PORT`, `LDAP_USESSL`, `LDAP_READUSER`, `LDAP_READPASS`, `LDAP_BASEDN`,
   `LDAP_USERNAMEFIELD` (use `sAMAccountName` for Active Directory, `uid`
   otherwise), `LDAP_CREATE_USER`.
2. **Local development** ships an OpenLDAP server (`ldap-dev` service) with
   seeded users from [`docker/ldap/users-only.ldif`](../docker/ldap/users-only.ldif),
   e.g. `developer` / `dev123`. Verify it responds:

   ```bash
   docker compose ps ldap-dev
   ldapsearch -x -H ldap://localhost:3389 \
     -D "cn=readuser,dc=dev,dc=local" -w readuser \
     -b "dc=dev,dc=local" '(uid=developer)'
   ```

3. **Username rejected before LDAP is even asked:** usernames longer than 256
   chars or containing characters outside `a-zA-Z0-9._@-` are rejected by the
   authenticator's validation.
4. **Deactivated account:** [`src/Security/UserChecker.php`](../src/Security/UserChecker.php)
   refuses login for users with `active = 0` in the `users` table.
5. **User exists in LDAP but not in TimeTracker:** with `LDAP_CREATE_USER=false`
   only pre-created users can log in. With `true`, first login auto-creates the
   user (type `DEV`, locale `de`); team assignment additionally requires the
   optional `config/ldap_ou_team_mapping.yml` (not shipped — a warning is
   logged and team mapping skipped when absent).
6. Authentication errors are logged with context in `var/log/dev.log` /
   `var/log/prod.log` (channel: security/LDAP messages from the authenticator).

## Frontend build issues (blank UI, 404 on /build-ui assets)

The SolidJS SPA in [`frontend/`](../frontend) is built with **bun** into
`public/build-ui` (Vite + `vite-plugin-symfony`). If `public/build-ui` is
missing or stale:

```bash
cd frontend
bun install --frozen-lockfile
bun run build

# or via the container:
make npm-build

# Dev server with HMR:
bun run dev        # or: make npm-dev
```

Frontend unit tests: `bun run test` (Vitest, see [`frontend/README.md`](../frontend/README.md)).
Hard-reload the browser (Ctrl+Shift+R) after rebuilding, since asset filenames
are content-hashed.

## E2E stack problems

`make e2e-up` builds the e2e image, creates `.env.test.local` from
[`.env.test.local.example`](../.env.test.local.example) if missing, starts the
`e2e` profile (`app-e2e`, `httpd-e2e`, `db-e2e`, `ldap-dev`) and polls
`http://localhost:8766/login` until it returns 200 (up to 60 s).

- **Readiness loop never succeeds:** check `docker compose logs app-e2e` and
  `docker compose logs db-e2e` — the app waits for the separate e2e database.
- **Port 8766 taken:** set `E2E_HTTP_PORT` in `.env`.
- **Deterministic time:** the e2e app runs with `APP_FROZEN_TIME=2024-01-15 12:00:00`
  matching [`sql/testdata.sql`](../sql/testdata.sql) — tests asserting on dates
  rely on this, don't "fix" it.
- Run tests against a running stack with `make e2e-run`; `make e2e` manages the
  stack itself; `make e2e-down` stops only the e2e services (leaves `ldap-dev`
  up for other profiles).

## Jira integration errors

Jira sync uses per-user OAuth tokens stored (encrypted) in the
`users_ticket_systems` table.

- **401 / "Jira authentication required":** the stored token is missing,
  expired, or was revoked in Jira. The API answers with a JSON 401 containing a
  `redirect_url` ([`src/EventSubscriber/ExceptionSubscriber.php`](../src/EventSubscriber/ExceptionSubscriber.php))
  — following it re-runs the OAuth flow (callback route: `/jiraoauthcallback`).
- **Sync silently skipped for a user:** `checkUserTicketSystem()`
  ([`src/Service/Integration/Jira/JiraAuthenticationService.php`](../src/Service/Integration/Jira/JiraAuthenticationService.php))
  returns false when the user has no token row or has set the
  "avoid connection" flag for that ticket system.
- **Tokens undecryptable after key change:** tokens are encrypted with
  `APP_ENCRYPTION_KEY` (fallback: `APP_SECRET`). Changing that key invalidates
  existing tokens — affected users must re-authorize via the OAuth flow.
- **Legacy plaintext tokens** (pre-encryption databases) are migrated with:

  ```bash
  docker compose exec app bin/console tt:encrypt-jira-tokens
  ```

- **Subticket mapping outdated:**

  ```bash
  docker compose exec app bin/console tt:sync-subtickets [project-id]
  ```

## Locale / language quirks

- The SPA is translated in **English and German** only
  ([`frontend/project.inlang/settings.json`](../frontend/project.inlang/settings.json)).
- The backend accepts `de`, `en`, `es`, `fr`, `ru` as user locales and
  normalizes anything else to `en`
  ([`src/Service/Util/LocalizationService.php`](../src/Service/Util/LocalizationService.php)).
- Each user picks their locale in **Settings**; it is stored per user and wins
  over instance defaults. New LDAP-created users start with `de`.
- `APP_LOCALE` (`.env`) is the instance-level default locale; the Symfony
  fallback locale is `en` ([`config/services.yaml`](../config/services.yaml)).

So: a user seeing German although the instance default is English almost always
has `de` stored in their own settings (or was just auto-created via LDAP).

## Test failures (PHPUnit)

```bash
make test            # full suite against db_unittest (Xdebug off, quiet)
make test-unit       # DB-free unit tests only
make test-verbose    # verbose output
make test-debug      # with Xdebug (XDEBUG_MODE=debug,develop)
make reset-test-db   # rebuild the unittest DB after schema changes
```

Tests run inside the dev container with `memory_limit=2G`
([`docker/php/test.ini`](../docker/php/test.ini) is mounted for that). See
[testing.md](testing.md) and
[DEVELOPER_PHPUNIT_CUSTOMIZATION.md](DEVELOPER_PHPUNIT_CUSTOMIZATION.md).

Static analysis and style (no DB needed, `tools` profile):

```bash
make stan            # PHPStan level 10
make check-all       # PHPStan + PHPat + cs-check + twig lint
make cs-fix          # auto-fix code style
```

## Useful Symfony debug commands

All standard, all available in the dev container
(`docker compose exec app-dev bash` or `make sh`):

```bash
bin/console debug:router            # all routes
bin/console debug:container         # services
bin/console debug:event-dispatcher  # listeners/subscribers
bin/console doctrine:schema:validate
bin/console cache:clear
```

## Getting help

- **GitHub issues:** <https://github.com/netresearch/timetracker/issues>
- Include: `php -v` / `bin/console --version` output, `APP_ENV`, relevant lines
  from `var/log/*.log` and `docker compose logs`, and steps to reproduce.

Related docs: [development.md](development.md) ·
[DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md) · [testing.md](testing.md) ·
[apcu-setup.md](apcu-setup.md) · [xdebug-setup.md](xdebug-setup.md)

# Configuration Guide

All configuration the application actually reads, verified against
[.env](../.env), [.env.example](../.env.example), [.env.test](../.env.test),
[compose.yml](../compose.yml) and [config/services.yaml](../config/services.yaml).

## How configuration is loaded

Two layers, two owners:

1. **Symfony (application)** — [symfony/dotenv](https://symfony.com/doc/current/configuration.html#configuring-environment-variables-in-env-files)
   loads `.env` → `.env.local` → `.env.$APP_ENV` → `.env.$APP_ENV.local`
   (later files win; real environment variables win over all files).
   Committed defaults live in [.env](../.env); put local overrides in the
   uncommitted `.env.local`.
2. **Docker Compose (infrastructure)** — [compose.yml](../compose.yml)
   interpolates variables like `${HTTP_PORT:-8765}` from the shell environment
   or the `.env` file. [.env.example](../.env.example) documents this layer.

## Application

Defined as parameters in [config/services.yaml](../config/services.yaml),
defaults in [.env](../.env):

| Variable | Default | Purpose |
|----------|---------|---------|
| `APP_ENV` | `dev` | Symfony environment (`dev`, `prod`, `test`) |
| `APP_DEBUG` | derived from `APP_ENV` | Debug mode |
| `APP_SECRET` | change it | Symfony kernel secret |
| `APP_ENCRYPTION_KEY` | falls back to `APP_SECRET` | Key for encrypting Jira OAuth tokens at rest (generate: `openssl rand -base64 32`) |
| `APP_TITLE` | `Netresearch TimeTracker` | Title shown in the UI |
| `APP_LOCALE` | `en` | Default locale |
| `APP_LOGO_URL` | `/images/logo-netresearch.svg` | Logo shown in the UI |
| `APP_HEADER_URL` | empty | Optional link target for the header/logo |
| `APP_SHOW_BILLABLE_FIELD_IN_EXPORT` | `false` | Include the billable column in exports |
| `REQUIRE_TWO_FACTOR` | `false` | Org-wide mandatory 2FA: every user must enrol a second factor (authenticator app or passkey) before they can use the app |

## Database

| Variable | Default ([.env](../.env)) | Purpose |
|----------|---------------------------|---------|
| `DATABASE_URL` | `mysql://timetracker:timetracker@db:3306/timetracker?serverVersion=mariadb-12.1.2` | Doctrine DBAL connection ([config/packages/doctrine.yaml](../config/packages/doctrine.yaml)) |

The test environment ([.env.test](../.env.test)) points at the dedicated
`db_unittest` container:
`mysql://unittest:unittest@db_unittest:3306/unittest?serverVersion=mariadb-12.1.2&charset=utf8mb4`
(no host port is published for it).

## Authentication (LDAP + local accounts)

Each account authenticates against exactly one source (ADR-018 D1): a user
with a stored password hash is a **local account** (verified against the hash;
LDAP is never consulted for it), any other user is an **LDAP account**. LDAP is
optional — leaving `LDAP_HOST` empty puts the instance in **local-only mode**
and only password accounts can log in.

Bootstrap a local admin (also the LDAP-outage escape hatch / password reset):

```bash
bin/console app:user:create <username> --type=ADMIN
```

Login attempts are throttled to 5 per username+IP.

### LDAP

Defaults in [.env](../.env) target the bundled `ldap-dev` container:

| Variable | Default | Purpose |
|----------|---------|---------|
| `LDAP_HOST` | `ldap-dev` | LDAP server host (**empty = local-only mode**) |
| `LDAP_PORT` | `389` | Port (`636` for LDAPS) |
| `LDAP_READUSER` | `cn=readuser,dc=dev,dc=local` | Read/bind user DN |
| `LDAP_READPASS` | `readuser` | Read/bind user password |
| `LDAP_BASEDN` | `dc=dev,dc=local` | Search base DN |
| `LDAP_USERNAMEFIELD` | `uid` | Username attribute (`sAMAccountName` for Active Directory) |
| `LDAP_USESSL` | `false` | Use SSL (legacy LDAPS) |
| `LDAP_CREATE_USER` | `true` | Auto-create TimeTracker users after successful LDAP authentication |

## Jira / ticket systems (admin UI, not env vars)

Jira integration is **not** configured through environment variables. Each
ticket system is a database record (`ticket_systems` table, entity
[src/Entity/TicketSystem.php](../src/Entity/TicketSystem.php)) managed in the
admin UI (*Administration → Ticket systems*, requires `ROLE_ADMIN`):

| Field | Meaning |
|-------|---------|
| Name | Unique display name |
| Type | `JIRA`, `OTRS` or `FRESHDESK` ([src/Enum/TicketSystemType.php](../src/Enum/TicketSystemType.php)); only Jira has a worklog integration |
| Book time | Whether worklogs are pushed to the ticket system |
| URL / Ticket URL | Base URL and per-ticket link pattern |
| Login / Password | Legacy basic credentials |
| Public/private key + OAuth consumer key/secret | OAuth 1.0a — for deployment type `SERVER` (Jira Server/Data Center) |
| OAuth2 client id/secret | OAuth 2.0 (3LO) — for deployment type `CLOUD` (Jira Cloud); the Atlassian `cloudId` is resolved automatically at first auth |

Deployment type is `SERVER` or `CLOUD`
([src/Enum/DeploymentType.php](../src/Enum/DeploymentType.php)). Secret fields
are never sent back to the browser (`TicketSystem::SECRET_KEYS`). Per-user
Jira access tokens are encrypted at rest with `APP_ENCRYPTION_KEY`; migrate
plaintext tokens with `bin/console tt:encrypt-jira-tokens`
([src/Command/EncryptJiraTokensCommand.php](../src/Command/EncryptJiraTokensCommand.php)).

### Setting up Jira Cloud (OAuth 2.0 / 3LO)

1. In the [Atlassian developer console](https://developer.atlassian.com/console/myapps/),
   create an **OAuth 2.0 (3LO)** app.
2. Add the **Jira platform REST API** with the scopes `read:jira-work` and
   `write:jira-work` (`offline_access` for refresh tokens is requested
   automatically at authorize time).
3. Register the callback URL — exactly
   `https://<your-timetracker>/jiraoauthcallback`, no query string (the ticket
   system id travels inside the encrypted `state` parameter).
4. In *Administration → Ticket systems*, set type `JIRA`, deployment type
   `CLOUD`, the site URL (`https://<site>.atlassian.net`), and the app's
   **Client ID** / **Client Secret**.
5. Each user authorizes once via the "Please authorize" link on their first
   sync; the Atlassian `cloudId` is resolved and stored automatically, access
   tokens refresh themselves (rotating refresh tokens).

### Tracking external tickets in an internal Jira project

A project can mirror worklogs for tickets from a *customer's* ticket system
into your *own* Jira: set **Internal Jira project key** and **Internal Jira
ticket system** on the project (admin UI; fields
`internal_jira_project_key` / `internal_jira_ticket_system` on
[src/Entity/Project.php](../src/Entity/Project.php)). When a user books time
on an external ticket, the app searches the internal project for an issue
whose summary references that ticket and creates one if none exists, then
books the worklog there
([src/EventSubscriber/EntryEventSubscriber.php](../src/EventSubscriber/EntryEventSubscriber.php)).

## Error tracking (Sentry)

| Variable | Default | Purpose |
|----------|---------|---------|
| `SENTRY_DSN` | empty (disabled) | DSN for [sentry/sentry-symfony](https://github.com/getsentry/sentry-symfony); set to enable error reporting. Disabled in tests via [config/packages/test/sentry.yaml](../config/packages/test/sentry.yaml) |

## Docker Compose level

Read by [compose.yml](../compose.yml) (template: [.env.example](../.env.example)):

| Variable | Default | Purpose |
|----------|---------|---------|
| `COMPOSE_PROFILES` | `dev` | Which services start: `dev`, `prod`, `tools`, `test`, `e2e` |
| `HTTP_PORT` | `8765` | Host port of the dev/prod web server |
| `E2E_HTTP_PORT` | `8766` | Host port of the e2e web server |
| `DB_ROOT_PASSWORD`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | `global123` / `timetracker` / `timetracker` / `timetracker` | Main database credentials |
| `UNITTEST_DB_ROOT_PASSWORD`, `UNITTEST_DB_USER`, `UNITTEST_DB_PASSWORD`, `UNITTEST_DB_NAME` | `global123` / `unittest` / `unittest` / `unittest` | Test database (`db_unittest`) credentials |
| `E2E_DB_ROOT_PASSWORD`, `E2E_DB_USER`, `E2E_DB_PASSWORD`, `E2E_DB_NAME` | `global123` / `timetracker` / `timetracker` / `timetracker` | E2E database (`db-e2e`) credentials |
| `LDAP_ADMIN_PASSWORD` | `admin123` | Dev LDAP directory admin password |
| `LDAP_READONLY_USER_USERNAME` / `LDAP_READONLY_USER_PASSWORD` | `readuser` / `readuser` | Dev LDAP read-only bind user |
| `TRUSTED_PROXY_ALL` | empty | If truthy, trust `X-Forwarded-*` headers from the direct remote address ([public/index.php](../public/index.php)) |
| `TRUSTED_PROXY_LIST` | empty | JSON array of trusted proxy IPs/ranges, e.g. `["10.0.0.0/8"]` ([public/index.php](../public/index.php)) |
| `COVERAGE_ENABLED` / `XDEBUG_MODE` | `0` / `off` | E2E backend coverage collection (see [testing.md](testing.md)) |

Note: [.env.example](../.env.example) still lists the test DB credentials as
`TEST_DB_*`, but [compose.yml](../compose.yml) reads them as `UNITTEST_DB_*`
(the defaults are identical either way).

## Test-only variables

| Variable | Where | Purpose |
|----------|-------|---------|
| `APP_FROZEN_TIME` | `app-e2e` service in [compose.yml](../compose.yml) (`2024-01-15 12:00:00`) | Freezes the application clock ([src/Service/ClockFactory.php](../src/Service/ClockFactory.php)) so e2e tests are deterministic against [sql/testdata.sql](../sql/testdata.sql) |
| `KERNEL_CLASS`, `SYMFONY_DEPRECATIONS_HELPER`, … | [.env.test](../.env.test), [phpunit.xml.dist](../phpunit.xml.dist) | Standard Symfony test harness settings |
| `VAR_DUMPER_SERVER` | [config/packages/dev/debug.yaml](../config/packages/dev/debug.yaml) | Target of the dev-only `server:dump` var-dumper server |

## Console commands

The application ships exactly two own commands
([src/Command/](../src/Command/)):

```bash
bin/console tt:encrypt-jira-tokens   # encrypt plaintext Jira OAuth tokens at rest (idempotent)
bin/console tt:sync-subtickets       # update project subtickets from Jira
```

For inspecting configuration use the standard Symfony tooling:
`bin/console debug:config`, `bin/console debug:container --env-vars`,
`bin/console debug:router`.

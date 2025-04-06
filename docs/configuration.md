# Application Configuration

The Netresearch TimeTracker application uses Symfony's standard environment variable configuration system. Configuration is primarily managed through `.env` files.

## Configuration Files

Configuration is loaded from the following files in order of precedence (later files override earlier ones):

1.  `.env`: Contains default configuration values. **Committed to the repository.**
2.  `.env.local`: Contains local overrides for development. **NOT committed.** Create this file to set variables specific to your machine (e.g., different database credentials).
3.  `.env.$APP_ENV`: Contains environment-specific defaults (e.g., `.env.test`, `.env.prod`). **Committed.**
4.  `.env.$APP_ENV.local`: Contains environment-specific local overrides. **NOT committed.**

Real system environment variables will always override values set in `.env` files.

**Security Note:** Never store production secrets directly in committed `.env` files. Use `.env.local`, system environment variables, or Symfony's secrets management for sensitive production values.

## Key Configuration Variables (`.env`)

These are the main variables defined in the base `.env` file. You might override them in `.env.local` for your development setup.

### Symfony Core

*   `APP_ENV`: The application environment (`dev`, `prod`, `test`). Determines debugging settings, loaded configuration, etc.
    *   Default: `dev`
*   `APP_SECRET`: A secret key used for CSRF protection and other security features. Should be a long, random, unique string.
    *   Default: `ThisTokenIsNotSoSecretChangeIt` ( **MUST be changed for production!**)

### Database

*   `DATABASE_URL`: The Data Source Name (DSN) for connecting to the primary database.
    *   Format: `driver://user:password@host:port/dbname?serverVersion=X.Y`
    *   Default: `mysql://timetracker:timetracker@db:3306/timetracker?serverVersion=8` (Connects to the `db` service defined in `compose.yml`)
    *   *Note:* The test environment typically uses a different database, configured in `.env.test`.

### Mailer

*   `MAILER_DSN`: Configuration for sending emails.
    *   Format examples: `smtp://user:pass@smtp.example.com:port`, `sendmail://default`
    *   Default: `smtp://localhost`

### Sentry (Error Tracking)

*   `SENTRY_DSN`: The Data Source Name for reporting errors to Sentry.io.
    *   Default: `""` (Disabled by default. Get a DSN from your Sentry project.)
    *   Configuration can also be managed in `config/packages/sentry.yaml` (or `config/sentry.yml` as mentioned in README).

### LDAP Authentication

*   `LDAP_HOST`: Hostname or IP address of the LDAP server.
    *   Default: `"127.0.0.1"`
*   `LDAP_PORT`: Port for the LDAP server. `0` uses default ports (389 for non-SSL, 636 for SSL).
    *   Default: `0`
*   `LDAP_READUSER`: Distinguished Name (DN) of the user used to bind and search the LDAP directory.
    *   Default: `"readuser"`
*   `LDAP_READPASS`: Password for the `LDAP_READUSER`.
    *   Default: `"readuser"`
*   `LDAP_BASEDN`: The base DN for searching users in the LDAP directory.
    *   Default: `"dc=company,dc=org"`
*   `LDAP_USERNAMEFIELD`: The LDAP attribute used as the username (e.g., `uid`, `sAMAccountName` for Active Directory).
    *   Default: `"uid"`
*   `LDAP_USESSL`: Whether to use SSL (ldaps://) for the connection.
    *   Default: `true`
*   `LDAP_CREATE_USER`: If `true`, automatically create a TimeTracker user (with DEV role) upon successful LDAP authentication if the user doesn't exist locally.
    *   Default: `true`

### Application Specific

*   `APP_LOCALE`: Default locale for the application (used for translations).
    *   Default: `"en"`
*   `APP_LOGO_URL`: URL path to the application logo image.
    *   Default: `"/build/images/logo.png"`
*   `APP_MONTHLY_OVERVIEW_URL`: Base URL for linking to an external monthly overview/statistics tool (like timalytics). The username is appended.
    *   Default: `"https://stats.timetracker.nr/?user="`
*   `APP_TITLE`: The title displayed in the browser tab/window.
    *   Default: `"Netresearch TimeTracker"`
*   `APP_HEADER_URL`: Optional URL for a custom header link/image.
    *   Default: `""`
*   `APP_SHOW_BILLABLE_FIELD_IN_EXPORT`: If `true`, include the "billable" field in XLSX exports.
    *   Default: `false`
*   `SERVICE_USERS`: Comma-separated list of usernames who are allowed to act on behalf of other users via the API.
    *   Default: `""`

### Trusted Proxies (Symfony Framework)

These variables control how Symfony handles requests coming through reverse proxies (like the Nginx container in the Docker setup).

*   `TRUSTED_PROXIES`: Defines which proxy IP addresses are trusted. Can be specific IPs, ranges, or `*` for all. See [Symfony Docs](https://symfony.com/doc/4.4/deployment/proxies.html).
    *   Example: `TRUSTED_PROXIES=192.0.0.1,10.0.0.0/8`
    *   *Note:* The `README.rst` mentions `TRUSTED_PROXY_LIST` and `TRUSTED_PROXY_ALL`, which might be custom implementations. The standard Symfony variable is `TRUSTED_PROXIES`.
*   `TRUSTED_HOSTS`: A regex pattern defining allowed host headers. Helps prevent host header injection attacks.
    *   Example: `TRUSTED_HOSTS='^localhost|example\.com$'`

## Environment-Specific Configuration

Check files like `config/packages/dev/`, `config/packages/prod/`, and `config/packages/test/` for configuration that applies only to specific environments (e.g., enabling the web profiler bar only in `dev`).

# Application Configuration

This document describes how to configure the application.

## Environment Variables (`.env`, `.env.local`)

The primary method for configuration is through environment variables, typically managed in `.env` files. See `docs/development.md` for setup instructions.

Key variables include:

*   `APP_ENV`: (`dev`, `prod`, `test`) Controls the application environment (debugging, logging levels, etc.).
*   `APP_SECRET`: A unique, random string crucial for security (CSRF protection, signed URLs).
*   `DATABASE_URL`: Connection string for the primary database.
    *   Example: `mysql://db_user:db_password@db_host:db_port/db_name?serverVersion=mariadb-10.5&charset=utf8mb4`
*   `MAILER_DSN`: Connection string for sending emails.
    *   Example (MailHog/Mailpit): `smtp://mailhog:1025`
    *   Example (SendGrid): `sendgrid://KEY@default`
*   `TRUSTED_PROXIES`: IP addresses of trusted reverse proxies (e.g., Nginx container, load balancer).
*   `TRUSTED_HOSTS`: Allowed host headers.

**Application-Specific Environment Variables:**

*   `APP_TITLE`: The title displayed in the browser tab/window (Default: "Netresearch TimeTracker").
*   `APP_LOCALE`: Default locale for the application (used for translations) (Default: "en").
*   `APP_LOGO_URL`: URL path to the application logo image (Default: "/build/images/logo.png").
*   `APP_MONTHLY_OVERVIEW_URL`: Base URL for linking to an external monthly overview/statistics tool (like Timalytics). The username is appended (Default: "https://stats.timetracker.nr/?user=").
*   `APP_HEADER_URL`: Optional URL for a custom header link/image (Default: "").
*   `APP_SHOW_BILLABLE_FIELD_IN_EXPORT`: If `true`, include the "billable" field in XLSX exports (Default: `false`).
*   `SERVICE_USERS`: Comma-separated list of usernames who are allowed to act on behalf of other users via the API (Default: "").
*   `LDAP_HOST`: Hostname or IP address of the LDAP server (Default: "127.0.0.1").
*   `LDAP_PORT`: Port for the LDAP server. `0` uses default ports (389 for non-SSL, 636 for SSL) (Default: `0`).
*   `LDAP_READUSER`: Distinguished Name (DN) of the user used to bind and search the LDAP directory (Default: "readuser").
*   `LDAP_READPASS`: Password for the `LDAP_READUSER` (Default: "readuser").
*   `LDAP_BASEDN`: The base DN for searching users in the LDAP directory (Default: "dc=company,dc=org").
*   `LDAP_USERNAMEFIELD`: The LDAP attribute used as the username (e.g., `uid`, `sAMAccountName` for Active Directory) (Default: "uid").
*   `LDAP_USESSL`: Whether to use SSL (ldaps://) for the connection (Default: `true`).
*   `LDAP_CREATE_USER`: If `true`, automatically create a TimeTracker user (with DEV role) upon successful LDAP authentication if the user doesn't exist locally (Default: `true`).
*   `SENTRY_DSN`: (Optional) The Data Source Name for reporting errors to Sentry.io (Default: ""). Check `config/packages/sentry.yaml` for more configuration.

## Symfony Configuration Files (`config/`)

Symfony bundles and application services are configured via YAML files in the `config/` directory.

*   **`config/packages/*.yaml`:** Configuration for installed bundles (Doctrine, Twig, Security, Monolog, etc.). These files are often environment-specific (e.g., `config/packages/dev/web_profiler.yaml`).
*   **`config/services.yaml`:** Defines application services, parameters, and autowiring/autoconfiguration rules.
*   **`config/routes.yaml` / `config/routes/*.yaml` / Controller Annotations:** Defines application routes.
    *   *Note:* While most routes are defined via Annotations in `src/Controller/` (loaded via `config/routes/annotations.yaml`), some legacy routes are still imported from `config/legacy_bundle/routing.yml` within `config/routes.yaml`. These are planned for migration to annotations.
*   **`config/secrets/`:** Manages deployment secrets (not typically committed to Git).

**(Add details about specific bundle configurations if they are complex or require explanation, e.g., security firewall setup, custom Doctrine types, complex routing)**

### Security (`config/packages/security.yaml`)

*   Describes firewalls (patterns, authentication methods like form login, LDAP, API tokens).
*   Defines user providers (e.g., Doctrine entity provider, LDAP provider).
*   Sets up password encoders/hashers.
*   Configures access control rules (role hierarchy, path-based permissions).

### Doctrine (`config/packages/doctrine.yaml`)

*   Configures database connections (though `DATABASE_URL` is often preferred).
*   Defines entity mappings (usually autodetected).
*   Sets up caching (metadata, query, result caches).

### Services (`config/services.yaml`)

*   Defines default autowiring/autoconfiguration settings.
*   Explicitly defines services that cannot be autowired.
*   Sets application parameters.

## Application-Specific Settings

*(If the application stores configuration in the database or uses other custom mechanisms, describe them here.)*

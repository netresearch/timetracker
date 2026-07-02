# Technology Stack

This document provides an overview of the technologies, frameworks, and tools used in this project.

## Backend

*   **PHP:** Version `8.5`.
*   **Symfony:** Version `8.1`.
*   **Doctrine:**
    *   **ORM 3:** (`doctrine/orm`, `doctrine/doctrine-bundle`) with PHP 8 attributes for metadata.
    *   **Migrations:** (`doctrine/doctrine-migrations-bundle`) for incremental schema changes.
*   **Twig:** (`twig/twig`, `symfony/twig-bundle`) Renders the server-side templates (SPA shell, login, error pages, CSV export).
*   **Monolog:** (`symfony/monolog-bundle`) Used for logging application events, errors, and debug information.
*   **Guzzle:** (`guzzlehttp/guzzle` + `guzzlehttp/oauth-subscriber`) HTTP client for the Jira integration (OAuth1).
*   **Laminas LDAP:** (`laminas/laminas-ldap`) LDAP/Active Directory authentication.
*   **PHPSpreadsheet:** (`phpoffice/phpspreadsheet`) XLSX export for controlling.
*   **Sentry:** (`sentry/sentry-symfony`) Real-time error tracking and monitoring.
*   **APCu:** In-memory backend of the Symfony app cache (see `docs/apcu-setup.md`).

## Frontend

The UI is a SolidJS single-page application in `frontend/`, served under `/ui`
(see `frontend/README.md`):

*   **SolidJS** 1.9 + **TypeScript** (strict)
*   **Vite** 8 with `vite-plugin-symfony`, built into `public/build-ui`
    (integrated via `pentatrion/vite-bundle`)
*   **Tailwind CSS** 4 + own design tokens (CSS custom properties, `light-dark()`)
*   **Ark UI** headless components
*   **TanStack Solid Query** for server state
*   **Paraglide JS** for i18n (DE/EN)
*   **bun** as the frontend package manager and script runner

## Development & Tooling

*   **Docker & Docker Compose:** Containerized development, test and production environments (`docker-bake.hcl` holds all image/version pins).
*   **Composer:** The dependency manager for PHP packages.
*   **npm (repo root):** Only the Playwright e2e tooling.
*   **PHPUnit 13:** (`phpunit/phpunit`) Unit, controller, and integration tests.
*   **Playwright:** Browser e2e suite in `e2e/` (with `@axe-core/playwright` for a11y checks).
*   **Vitest:** Frontend unit tests (jsdom).
*   **PHPStan:** (`phpstan/phpstan`, level 10) Static analysis.
*   **PHPat:** (`phpat/phpat`) Architecture rules on top of PHPStan.
*   **PHP-CS-Fixer:** (`friendsofphp/php-cs-fixer`) Enforces the code style.
*   **Rector:** (`rector/rector`) Automated refactoring and upgrades.
*   **CaptainHook:** Git hooks running the quality gates before each commit.

## Infrastructure

*   **Nginx:** (`nginx:alpine` Docker image) Web server and reverse proxy, serving static assets and forwarding PHP requests to the application container (PHP-FPM).
*   **MariaDB:** (`mariadb:12.1` Docker image) The relational database used to store application data.

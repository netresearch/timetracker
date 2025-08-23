# Netresearch TimeTracker — AGENT.md

This file is the canonical, tool-agnostic guide for agents working in this repository. It consolidates how to build, test, lint, and navigate the project. It follows the AGENT.md guidance described at `https://ampcode.com/AGENT.md`.

## Project Overview

TimeTracker is a Symfony-based PHP application for project/customer-centric time tracking with Jira integration, LDAP authentication, reports, and exports.

- Backend: PHP 8.2, Symfony 6.4, Doctrine ORM, Twig, Monolog
- Frontend assets: Webpack Encore, Stimulus, Sass
- Infrastructure: Docker Compose (Nginx, PHP-FPM, MariaDB)
- Tests: PHPUnit; static analysis via PHPStan and Psalm
- API: OpenAPI v3 at `public/api.yml` (Swagger UI at `/docs/swagger/index.html`)

See also: @README.rst, @docs/techstack.md, @docs/features.md

## Project Structure and Organization

- `src/` — Application code (controllers, services under `App\Service`, entities, security, etc.)
- `templates/` — Twig templates
- `config/` — Symfony configuration, routes, services
- `public/` — Web root and front controller
- `migrations/` — Doctrine migrations
- `assets/` — Frontend source assets (processed by Webpack Encore)
- `tests/` — PHPUnit tests
- `sql/` — SQL dumps and test data
- `docs/` — Developer documentation and checklists
- `compose*.yml`, `Dockerfile` — Containerization

Ongoing refactors move legacy helpers into services under `App\Service` (see @PLANNING.md and @TASKS.md).

## Build, Test, and Development Commands

Run commands through Docker Compose unless explicitly stated otherwise.

### Setup

```bash
cp .env .env.local
docker compose up -d --build
docker compose run --rm app composer install
docker compose run --rm app npm install
# Database
docker compose run --rm app bin/console doctrine:database:create --if-not-exists
docker compose run --rm app bin/console doctrine:migrations:migrate -n
```

### Develop

```bash
# Symfony cache (optional)
docker compose run --rm app bin/console cache:clear

# Frontend assets
docker compose run --rm app npm run dev
# Or watch changes
# docker compose run --rm app npm run watch
```

### Tests

```bash
# PHPUnit (all tests)
docker compose run --rm -e APP_ENV=test app bin/phpunit

# Coverage (HTML in var/coverage)
docker compose run --rm -e APP_ENV=test app bin/phpunit --coverage-html var/coverage

# Composer script, if preferred
docker compose run --rm -e APP_ENV=test app composer test
```

See @docs/testing.md for database prep and narrow test selection.

### Code Quality

```bash
# PHP_CodeSniffer
docker compose run --rm app composer cs-check
docker compose run --rm app composer cs-fix

# PHPStan / Psalm
docker compose run --rm app composer analyze
docker compose run --rm app composer psalm

# Security check (local security checker)
docker compose run --rm app composer security-check
```

### Frontend Build (production assets)

```bash
docker compose run --rm app npm run build
```

## Code Style and Conventions

- PHP code style: PSR-12; enforce via PHP_CodeSniffer and PHP-CS-Fixer
- Strict typing: prefer `declare(strict_types=1);` and typed parameters/returns
- Clear, descriptive names for classes, services, and variables
- Prefer dependency injection for services (Symfony autowiring/autoconfiguration)
- Keep controllers thin; move business logic into dedicated services under `App\Service`

Refer to configured tooling and rules in `phpcs.xml`, `phpstan.neon`, `psalm.xml`, and `rector.php`.

## Architecture and Design

- MVC using Symfony 6.4; routing via PHP attributes and YAML (legacy routes being migrated)
- Persistence via Doctrine ORM; migrations under `migrations/`
- Views with Twig templates under `templates/`
- Service layer under `App\Service` provides cohesive units of business logic and integrations (e.g., Jira, LDAP)
- Frontend assets built by Webpack Encore (`assets/` -> `public/build/`)

See @docs/techstack.md and @PLANNING.md for upgrade path and refactors.

## Testing Guidelines

- Follow the testing pyramid (unit > integration > functional)
- Tests live under `tests/`, mirroring `src/`
- Use `Symfony\Bundle\FrameworkBundle\Test\WebTestCase` for functional tests
- Enable coverage locally when needed

Detailed instructions: @docs/testing.md

## Security Considerations

- Never commit secrets; use environment variables and Symfony secrets for production
- Validate and sanitize inputs; rely on Symfony Validator and Forms when appropriate
- Ensure CSRF protection on non-GET forms
- Use prepared statements (Doctrine) for DB interactions
- Set secure cookie flags and proper headers via Symfony security configuration
- Keep dependencies up-to-date; run security checks regularly

Checklist: @docs/security-checklist.md

## Configuration

Primary configuration is via `.env` and `.env.local`:

- App: `APP_ENV`, `APP_SECRET`, `TRUSTED_PROXIES`, `TRUSTED_HOSTS`
- Database: `DATABASE_URL`
- Mailer: `MAILER_DSN`
- LDAP: `LDAP_*` variables to enable directory auth
- Jira: OAuth keys and settings for worklog synchronization
- Sentry: `SENTRY_DSN`
- App-specific: title, locale, logo, monthly overview URL, service users, and export flags

See @docs/configuration.md and relevant notes in @README.rst (e.g., Jira OAuth setup, trusted proxies).

## Useful Service Endpoints

- Swagger UI: `/docs/swagger/index.html`
- OpenAPI spec: `public/api.yml`

## Agent Notes

- Prefer running commands through Docker Compose to ensure correct PHP and extensions
- When asked to lint, analyze, or test, use the composer scripts where available (see `composer.json`)
- For legacy routes and helpers, consult @PLANNING.md and @TASKS.md before refactoring

---

Authoritative reference for this format: `https://ampcode.com/AGENT.md`.



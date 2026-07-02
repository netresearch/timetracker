# GitHub Copilot Instructions for TimeTracker

This file provides custom instructions for GitHub Copilot coding agent when working on the TimeTracker repository.

## Project Overview

TimeTracker is a Symfony-based PHP application for project/customer-centric time tracking with Jira integration, LDAP authentication, reports, and exports.

**Technology Stack:**
- Backend: PHP 8.5, Symfony 8, Doctrine ORM 3, Twig, Monolog
- Frontend: SolidJS, TypeScript, Vite, Tailwind CSS (in `frontend/`, bun)
- Infrastructure: Docker Compose (Nginx, PHP-FPM, MariaDB)
- Tests: PHPUnit 13, Playwright (e2e), Vitest (frontend)
- Static Analysis: PHPStan level 10, PHPat
- API: OpenAPI v3 at `public/api.yml`

## Essential Commands

All commands should be run through Docker Compose:

### Setup
```bash
cp .env .env.local
docker bake app-dev           # Build development image
docker compose up -d          # Start services
docker compose run --rm app-dev bin/console doctrine:database:create --if-not-exists
docker compose run --rm app-dev bin/console doctrine:migrations:migrate -n
```

### Testing
```bash
# Run all PHPUnit tests
docker compose run --rm -e APP_ENV=test app-dev bin/phpunit

# Run tests with coverage
docker compose run --rm -e APP_ENV=test app-dev bin/phpunit --coverage-html var/coverage

# Or use composer script
docker compose run --rm -e APP_ENV=test app-dev composer test
```

### Code Quality
```bash
# Check code style
COMPOSE_PROFILES=tools docker compose run --rm app-tools composer cs-check

# Fix code style
COMPOSE_PROFILES=tools docker compose run --rm app-tools composer cs-fix

# Run PHPStan analysis
COMPOSE_PROFILES=tools docker compose run --rm app-tools composer analyze

# Security audit
COMPOSE_PROFILES=tools docker compose run --rm app-tools composer audit
```

### Frontend Build (bun, in frontend/)
```bash
# Development server with HMR
cd frontend && bun install && bun run dev

# Production build (outputs to public/build-ui)
cd frontend && bun run build
```

## Code Style and Standards

- **PHP Code Style:** Follow PSR-12 standards
- **Strict Typing:** Always use `declare(strict_types=1);` at the top of PHP files
- **Type Hints:** Use typed parameters and return types
- **Naming:** Use clear, descriptive names for classes, services, and variables
- **Dependency Injection:** Prefer Symfony autowiring/autoconfiguration
- **Controllers:** Keep controllers thin; move business logic to services under `App\Service`

## Architecture Patterns

- **MVC Pattern:** Using Symfony 8 framework
- **Routing:** PHP attributes for new routes (legacy YAML routes being migrated)
- **Persistence:** Doctrine ORM with migrations in `migrations/`
- **Views:** Twig templates in `templates/`
- **Services:** Business logic in `App\Service` namespace
- **Frontend:** SolidJS + TypeScript in `frontend/`, built by Vite (bun) to `public/build-ui/`

## Testing Guidelines

- Follow the testing pyramid: unit > integration > functional
- Tests mirror `src/` structure in `tests/` directory
- Use `Symfony\Bundle\FrameworkBundle\Test\WebTestCase` for functional tests
- Write tests before or alongside code changes
- Ensure all new code has appropriate test coverage

## Security Requirements

- **Never commit secrets** - use environment variables and Symfony secrets
- **Input Validation:** Use Symfony Validator and Forms
- **CSRF Protection:** Ensure CSRF tokens on non-GET forms
- **SQL Injection:** Always use Doctrine ORM (prepared statements)
- **Dependencies:** Keep up-to-date and run security checks regularly

## Pull Request Requirements

Before submitting any PR:

1. **Run Code Style Checks:**
   ```bash
   COMPOSE_PROFILES=tools docker compose run --rm app-tools composer cs-check
   ```

2. **Run Static Analysis:**
   ```bash
   COMPOSE_PROFILES=tools docker compose run --rm app-tools composer analyze
   ```

3. **Run Tests:**
   ```bash
   docker compose run --rm -e APP_ENV=test app-dev bin/phpunit
   ```

4. **Security Audit:**
   ```bash
   COMPOSE_PROFILES=tools docker compose run --rm app-tools composer audit
   ```

## Project Structure

- `src/` - Application code (controllers, services, entities, security)
- `templates/` - Twig templates
- `config/` - Symfony configuration, routes, services
- `public/` - Web root and front controller
- `migrations/` - Doctrine migrations
- `frontend/` - SolidJS frontend sources (Vite/bun)
- `tests/` - PHPUnit tests
- `docs/` - Developer documentation

## Important Notes

- **Run commands in Docker:** Always use `docker compose run --rm app-dev` (or `app-tools`) prefix
- **Service Layer:** Business logic lives in `App\Service`
- **Configuration:** Primary config via `.env` and `.env.local`
- **Documentation:** Update relevant docs when making changes
- **Minimal Changes:** Make surgical, focused changes - don't refactor unrelated code

## Reference Documentation

For more details, see:
- `AGENTS.md` - Comprehensive agent guide
- `README.md` - Project setup and overview
- `docs/techstack.md` - Technology stack details
- `docs/testing.md` - Testing procedures
- `docs/security.md` - Security guidelines
- `docs/configuration.md` - Configuration options

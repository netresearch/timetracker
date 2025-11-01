# GitHub Copilot Instructions for TimeTracker

This file provides custom instructions for GitHub Copilot coding agent when working on the TimeTracker repository.

## Project Overview

TimeTracker is a Symfony-based PHP application for project/customer-centric time tracking with Jira integration, LDAP authentication, reports, and exports.

**Technology Stack:**
- Backend: PHP 8.4, Symfony 7.3, Doctrine ORM 3, Twig, Monolog
- Frontend: Webpack Encore, Stimulus, Sass
- Infrastructure: Docker Compose (Nginx, PHP-FPM, MariaDB)
- Tests: PHPUnit 12
- Static Analysis: PHPStan, Psalm (Psalm clean)
- API: OpenAPI v3 at `public/api.yml`

## Essential Commands

All commands should be run through Docker Compose:

### Setup
```bash
cp .env .env.local
docker compose up -d --build
docker compose run --rm app composer install
docker compose run --rm app npm install
docker compose run --rm app bin/console doctrine:database:create --if-not-exists
docker compose run --rm app bin/console doctrine:migrations:migrate -n
```

### Testing
```bash
# Run all PHPUnit tests
docker compose run --rm -e APP_ENV=test app bin/phpunit

# Run tests with coverage
docker compose run --rm -e APP_ENV=test app bin/phpunit --coverage-html var/coverage

# Or use composer script
docker compose run --rm -e APP_ENV=test app composer test
```

### Code Quality
```bash
# Check code style
docker compose run --rm app composer cs-check

# Fix code style
docker compose run --rm app composer cs-fix

# Run PHPStan analysis
docker compose run --rm app composer analyze

# Run Psalm analysis
docker compose run --rm app composer psalm

# Security check
docker compose run --rm app composer security-check
```

### Frontend Build
```bash
# Development build
docker compose run --rm app npm run dev

# Production build
docker compose run --rm app npm run build
```

## Code Style and Standards

- **PHP Code Style:** Follow PSR-12 standards
- **Strict Typing:** Always use `declare(strict_types=1);` at the top of PHP files
- **Type Hints:** Use typed parameters and return types
- **Naming:** Use clear, descriptive names for classes, services, and variables
- **Dependency Injection:** Prefer Symfony autowiring/autoconfiguration
- **Controllers:** Keep controllers thin; move business logic to services under `App\Service`

## Architecture Patterns

- **MVC Pattern:** Using Symfony 7.3 framework
- **Routing:** PHP attributes for new routes (legacy YAML routes being migrated)
- **Persistence:** Doctrine ORM with migrations in `migrations/`
- **Views:** Twig templates in `templates/`
- **Services:** Business logic in `App\Service` namespace
- **Frontend:** Webpack Encore builds assets from `assets/` to `public/build/`

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
   docker compose run --rm app composer cs-check
   ```

2. **Run Static Analysis:**
   ```bash
   docker compose run --rm app composer analyze
   docker compose run --rm app composer psalm
   ```

3. **Run Tests:**
   ```bash
   docker compose run --rm -e APP_ENV=test app bin/phpunit
   ```

4. **Security Check:**
   ```bash
   docker compose run --rm app composer security-check
   ```

## Project Structure

- `src/` - Application code (controllers, services, entities, security)
- `templates/` - Twig templates
- `config/` - Symfony configuration, routes, services
- `public/` - Web root and front controller
- `migrations/` - Doctrine migrations
- `assets/` - Frontend source assets
- `tests/` - PHPUnit tests
- `docs/` - Developer documentation

## Important Notes

- **Run commands in Docker:** Always use `docker compose run --rm app` prefix
- **Service Layer:** Ongoing refactor moves helpers to `App\Service` (see PLANNING.md)
- **Configuration:** Primary config via `.env` and `.env.local`
- **Documentation:** Update relevant docs when making changes
- **Minimal Changes:** Make surgical, focused changes - don't refactor unrelated code

## Reference Documentation

For more details, see:
- `AGENTS.md` - Comprehensive agent guide
- `README.rst` - Project setup and overview
- `docs/techstack.md` - Technology stack details
- `docs/testing.md` - Testing procedures
- `docs/security-checklist.md` - Security guidelines
- `docs/configuration.md` - Configuration options
- `PLANNING.md` - Upgrade path and refactors
- `TASKS.md` - Current tasks and progress

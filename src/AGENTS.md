<!-- Managed by agent: keep sections and order; edit content, not structure. -->

# AGENTS.md — src/

## Overview

Symfony 7.3 application code: controllers, services, entities, repositories, security, and commands.
Uses Doctrine ORM 3, Twig templating, and PSR-12 coding standards.

## Setup & environment

- Install: `composer install`
- PHP version: 8.4
- Framework: Symfony 7.3
- Required extensions: `ldap`, `pdo_mysql`, `mbstring`, `json`, `intl`
- Environment: Copy `.env` to `.env.local` and configure `DATABASE_URL`, `LDAP_*`

## Build & tests (prefer file-scoped)

- Typecheck a file: `bin/phpstan analyze src/Path/To/File.php --level=9`
- Format a file: `bin/php-cs-fixer fix src/Path/To/File.php`
- Lint a file: `php -l src/Path/To/File.php`
- Test a file: `bin/phpunit tests/Path/To/FileTest.php`
- Full build: `composer analyze && composer cs-check && composer test`

## Code style & conventions

- Follow PSR-12 coding standard
- Use strict types: `declare(strict_types=1);`
- Type hints: always use for parameters and return types
- Naming: `camelCase` for methods, `PascalCase` for classes
- Visibility: always declare (public, protected, private)
- PHPDoc: required for public APIs, include `@param` and `@return`

### Symfony-specific

- Controllers: thin, delegate to services under `App\Service`
- Routing: use PHP 8 attributes (`#[Route(...)]`)
- DI: prefer constructor injection with autowiring
- Entities: use typed properties, no setters where possible
- Repositories: extend `ServiceEntityRepository`, use query builders

## Security & safety

- Validate and sanitize all user inputs via Symfony Validator
- Use Doctrine prepared statements (never concatenate SQL)
- Escape output in Twig templates (`{{ var|e }}` is default)
- Never execute dynamic code or use unsafe functions
- Sensitive data: never log or expose in errors
- CSRF protection: enable for all forms
- XSS protection: escape all user-generated content

## PR/commit checklist

- [ ] Tests pass: `composer test`
- [ ] PHPStan Level 9 clean: `composer analyze`
- [ ] PSR-12 compliant: `composer cs-check`
- [ ] No deprecated functions used
- [ ] Public methods have PHPDoc
- [ ] Security: inputs validated, outputs escaped
- [ ] Migrations: included if schema changed

## Good vs. bad examples

**Good**: Proper type hints and strict types
```php
declare(strict_types=1);

final class TimeCalculationService
{
    public function calculateDuration(int $startMinutes, int $endMinutes): int
    {
        return $endMinutes - $startMinutes;
    }
}
```

**Bad**: Missing type hints
```php
class TimeCalculationService
{
    public function calculateDuration($start, $end)
    {
        return $end - $start;
    }
}
```

**Good**: Thin controller with service
```php
#[Route('/api/entries', methods: ['GET'])]
public function list(EntryService $entryService): JsonResponse
{
    return new JsonResponse($entryService->getEntries());
}
```

**Bad**: Fat controller with business logic
```php
#[Route('/api/entries', methods: ['GET'])]
public function list(EntityManagerInterface $em): JsonResponse
{
    $entries = $em->getRepository(Entry::class)->findAll();
    $result = [];
    foreach ($entries as $entry) {
        // ... lots of transformation logic
    }
    return new JsonResponse($result);
}
```

## When stuck

- Symfony docs: https://symfony.com/doc/current/
- Doctrine ORM: https://www.doctrine-project.org/projects/orm.html
- Review existing patterns in this codebase
- Check root AGENTS.md for project-wide conventions

## House Rules

- Keep controllers in `Controller/` organized by feature (e.g., `Interpretation/`, `Status/`)
- Services go in `Service/` with clear single responsibilities
- Entities are read-only where possible; mutations via services
- Use `final` classes unless inheritance is explicitly needed

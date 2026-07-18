<!-- Managed by agent: keep sections and order; edit content, not structure. -->

# AGENTS.md — src/

## Overview

Symfony 8 application code: controllers, services, entities, repositories, security, and commands.
Uses Doctrine ORM 3, Twig templating, and PER-CS + Symfony coding style.

## Setup & environment

- Install: `composer install`
- PHP version: 8.5
- Framework: Symfony 8
- Required extensions: `ldap`, `pdo_mysql`, `mbstring`, `json`, `intl`
- Environment: Copy `.env` to `.env.local` and configure `DATABASE_URL`, `LDAP_*`

## Build & tests (prefer file-scoped)

- Typecheck a file: `bin/phpstan analyze src/Path/To/File.php` (level 10 via phpstan.neon)
- Format a file: `bin/php-cs-fixer fix src/Path/To/File.php`
- Lint a file: `php -l src/Path/To/File.php`
- Test a file: `bin/phpunit tests/Path/To/FileTest.php`
- Full build: `composer analyze && composer cs-check && composer test`

## Code style & conventions

- Follow PER-CS + Symfony coding style (enforced by PHP-CS-Fixer, see `.php-cs-fixer.dist.php`)
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
- [ ] PHPStan level 10 clean: `composer analyze`
- [ ] Code style clean (PER-CS + Symfony): `composer cs-check`
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
- On any "validation blocks deactivate/re-save" report, grandfather the ENTIRE
  `*SaveDto` constraint family in one pass (uniqueness + length + format), not
  just the constraint that was hit: when editing an existing entity (`id > 0`)
  whose persisted value is unchanged, skip the check (fetch current by id,
  compare `(string) $current->getX() === $value` so a NULL persisted value
  equals `''`). Declarative `#[Assert\NotBlank/Length]` can't see the DB —
  convert to a custom validator (e.g. `ValidUserAbbr`) to grandfather. Narrow
  nullable DTO ids before `> 0`
- `App\Entity\Holiday` has a `DateTime` primary key the ORM can't manage
  (persist/hydrate blow up stringifying the identifier) — do ALL holiday read
  AND write via DBAL (`$connection->fetchAllAssociative`/`insert`/`delete`),
  never the ORM; reference `GetHolidaysAction`. Holidays are create+delete only
  (immutable). Validate dates with `#[Assert\Date]`, never `new DateTime()`
  (it rolls impossible dates over instead of throwing). The list exposes a
  synthetic `Ymd` int `id` for the grid; delete keys on `{ day }`
- The production image self-migrates on container start
  (`docker/php/docker-entrypoint.sh`, opt out `AUTO_MIGRATE=0`): waits for the
  DB, runs pending Doctrine migrations, then execs php-fpm. `sql/full.sql`
  seeds `doctrine_migration_versions` so a fresh install reads as
  up-to-date; an un-versioned legacy DB (schema present, 0 version rows) is
  baselined from live schema — but a PARTIALLY-versioned DB is NOT
  auto-baselined, so pre-flight `doctrine:migrations:version '<FQCN>' --add`
  any present-but-unrecorded migration before deploying. Use `dbal:run-sql`
  (DoctrineBundle 3.2.4 dropped `doctrine:query:sql`)

## Deployment, CI & merge (operational)

- Merge gate for `netresearch/timetracker` (PRs to `main`) has two required
  parts: the `Copilot review for default branch` ruleset (a Copilot review on
  the HEAD commit — feature PRs open as DRAFTS, `gh pr ready` un-drafts and
  triggers Copilot) AND the required status check `CI Success`
  (`.github/workflows/ci.yml` `ci-success` job — a single aggregate over
  `[setup, frontend, lint, test-unit, test-integration, e2e]`, added so branch
  protection needs one check instead of enumerating all jobs + the e2e
  shards). Codecov/SonarCloud are NOT part of that aggregate — they stay
  reporting-only and a red result there does not block merge. Rector is a
  CI-only lint gate, NOT in CaptainHook — run `composer rector`/`--dry-run` on
  changed PHP before pushing. Local PHPStan gives false negatives from a stale
  shared `var/cache` container XML — warm the cache before trusting it (never
  `cache:clear --no-warmup`). codecov's e2e coverage upload is flaky —
  `gh run rerun` heals it, keeping the head SHA
- Prod = `tt.netresearch.de`, container `timetracker` (php-fpm) fronted by
  `timetracker_httpd` (nginx) on `utility3.nr` (SSH `root@utility3.nr`, default
  ssh-agent) — `tt.netresearch.de` IS PRODUCTION, there is NO separate "review
  environment" despite the domain name; never offer a "review deploy" there.
  "Hot-deploy the last main merge" = pull the floating
  `ghcr.io/netresearch/timetracker:production` tag (verify
  `org.opencontainers.image.revision` == the merge commit and its
  `docker-publish.yml` run is success). Frontend assets live in the persisted
  volume `timetracker_pub_v5`, so `docker cp build-ui` out of the new image
  into the volume AFTER the container is healthy (rollback-safe). Attach BOTH
  `timetracker` + `mariadb` nets before start (`docker create → network
  connect → start`). Park the old container as `timetracker_prod_rollback`.
  Migrating deploys: back up first (`mariadb-dump` via the URL user — root has
  no pw) and pre-flight each pending migration's columns. For local testing use
  the dev compose stack (`COMPOSE_PROFILES=dev`, port 8765, `APP_ENV=dev`)

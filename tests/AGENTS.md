<!-- Managed by agent: keep sections and order; edit content, not structure. -->

# AGENTS.md — tests/

## Overview

PHPUnit 13 test suite: unit tests, controller tests, and integration tests.
Uses the Symfony test framework.

## Setup & environment

- Install: `composer install`
- Test database: `db_unittest` container (MariaDB) with `unittest` credentials
- Environment: `APP_ENV=test` is set automatically by test scripts
- Database setup: Test data loaded from `sql/unittest/` SQL files
- `app-dev` image: `ghcr.io/netresearch/timetracker:dev` is not published
  (`manifest unknown`); build it from the repository root with
  `make bake-dev` (`docker bake app-dev`) before the first container run
- Host install in a git worktree: `composer install` aborts in the CaptainHook
  plugin (`git directory not found`), and the abort also skips
  `phpstan/extension-installer`, so PHPStan later rejects the `symfony`
  parameter of its config (`Unexpected item`). Where the host PHP also lacks
  extensions the container has, install with
  `CAPTAINHOOK_DISABLE=true composer install --ignore-platform-req=ext-ldap --ignore-platform-req=ext-pdo_mysql`
  (one `--ignore-platform-req=ext-<name>` per extension composer reports
  missing). The static tools then run on the host; PHPUnit still runs in the
  container

## Build & tests (prefer file-scoped)

- Run single test file: `bin/phpunit tests/Path/To/FileTest.php`
- Run single test method: `bin/phpunit --filter testMethodName`
- Run unit tests only: `composer test:unit`
- Run controller tests: `composer test:controller`
- Run all tests: `composer test`
- Coverage report: `composer test:coverage`

## Code style & conventions

- Follow PER-CS + Symfony coding style (enforced by PHP-CS-Fixer)
- Test classes: `*Test.php` suffix, extend appropriate base class
- Test methods: `test*` prefix or `#[Test]` attribute
- One assertion focus per test method
- Use data providers for parameterized tests

### Test organization

- `Unit/` — Pure unit tests, no database, fast execution
- `Controller/` — Functional tests using `WebTestCase`
- `Integration/` — Tests requiring external services
- `Fixtures/` — Shared test data and factories

### Base classes

- `PHPUnit\Framework\TestCase` — Pure unit tests
- `Symfony\Bundle\FrameworkBundle\Test\KernelTestCase` — Service tests
- `Symfony\Bundle\FrameworkBundle\Test\WebTestCase` — Controller tests

## Security & safety

- Never use production database credentials in tests
- Test data should be isolated and repeatable
- Clean up any created resources in tearDown
- Mock external services (Jira, LDAP) in unit tests

## PR/commit checklist

- [ ] All tests pass: `composer test`
- [ ] New code has test coverage
- [ ] Tests are deterministic (no random failures)
- [ ] Test names clearly describe behavior being tested
- [ ] No hardcoded dates/times (use Clock abstraction)

## Good vs. bad examples

**Good**: Descriptive test name and single assertion
```php
public function testCalculateDurationReturnsCorrectMinutes(): void
{
    $service = new TimeCalculationService();

    $result = $service->calculateDuration(480, 540);

    $this->assertSame(60, $result);
}
```

**Bad**: Vague name and multiple unrelated assertions
```php
public function testService(): void
{
    $service = new TimeCalculationService();
    $this->assertInstanceOf(TimeCalculationService::class, $service);
    $this->assertSame(60, $service->calculateDuration(480, 540));
    $this->assertSame(0, $service->calculateDuration(0, 0));
}
```

**Good**: Data provider for parameterized tests
```php
#[DataProvider('durationProvider')]
public function testCalculateDuration(int $start, int $end, int $expected): void
{
    $result = $this->service->calculateDuration($start, $end);
    $this->assertSame($expected, $result);
}

public static function durationProvider(): array
{
    return [
        'one hour' => [480, 540, 60],
        'zero duration' => [480, 480, 0],
        'full day' => [0, 1440, 1440],
    ];
}
```

**Good**: Mocking external dependencies
```php
public function testSyncWithJiraHandlesApiError(): void
{
    $jiraClient = $this->createMock(JiraClient::class);
    $jiraClient->method('getWorklogs')
        ->willThrowException(new JiraApiException('Connection failed'));

    $service = new JiraSyncService($jiraClient);

    $this->expectException(SyncFailedException::class);
    $service->sync();
}
```

## When stuck

- PHPUnit docs: https://docs.phpunit.de/en/13.0/
- Symfony testing: https://symfony.com/doc/current/testing.html
- Review existing test patterns in this codebase
- Check `config/testing/` for PHPUnit configurations

## House Rules

- Unit tests must not touch the database
- Controller tests use the test database (`db_unittest`)
- Use `#[Group('...')]` for test categorization
- Performance tests go in `Performance/` with `#[Group('performance')]`
- Prefer `assertSame()` over `assertEquals()` for strict comparison
- Write portable `LIKE` queries: escape `%`/`_` and spell out `ESCAPE`
  explicitly (`LIKE :p ESCAPE '|'`, not a default backslash) — SQLite (used by
  some query-builder tests) has no default LIKE escape char, unlike
  MySQL/MariaDB (see `EntryRepository::applyInterpretationExtraFilters`)
- A functional test can't change the logged-in user via raw SQL and have a
  validator see it: `logInSession()` loads the user into the EM identity map,
  so a validator's `find($loggedInUserId)` returns the CACHED entity — a raw
  `UPDATE users …` is invisible to it. Unit-test such validators (mock `find`)
  or operate on a non-logged-in entity
- "Today" in application code comes from `App\Service\ClockInterface`, which
  `config/services_test.yaml` aliases to `Tests\Service\TestClock`, frozen at
  2023-10-24 12:00. `Psr\Clock\ClockInterface` is not aliased there and
  returns the real date, so a test that builds its date range from it misses
  the entries that code reading the App clock (e.g. `EntryRepository`) sees.
  Take dates in tests from the App clock (`->today()`)

## `.env.test.local` hijacks PHPUnit's database

`make e2e-up` writes `.env.test.local` whose `DATABASE_URL` points at the dev `db` — Symfony dotenv loads it LAST for any `APP_ENV=test` process, so `bin/phpunit` silently runs against the (often migration-behind) dev DB and fails with `SQLSTATE 1054 Unknown column` on recently added columns. Do NOT delete the file (the e2e stack needs it); override with a REAL env var instead, which dotenv never overrides:

```bash
docker compose --profile dev exec -T -e APP_ENV=test \
  -e 'DATABASE_URL=mysql://unittest:unittest@db_unittest:3306/unittest?serverVersion=mariadb-12.3.3&charset=utf8mb4' \
  app-dev bin/phpunit tests/...
```

`db_unittest` seeds `sql/unittest/00{1,2}_*.sql` only on FIRST volume init; `001_testtables.sql` is a gitignored artifact regenerated from `sql/full.sql`. A stale schema means: recreate the volume — `make reset-test-db` regenerates `001_testtables.sql`, removes the volume and starts `db_unittest` again. The typical symptom, with `DATABASE_URL` already pointing at `db_unittest`, is a full run with about 200 errors `Unknown column …` right after rebasing onto a `main` that added a migration: a stale test schema, not a regression.

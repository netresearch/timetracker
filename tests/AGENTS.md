<!-- Managed by agent: keep sections and order; edit content, not structure. -->

# AGENTS.md — tests/

## Overview

PHPUnit 12 test suite: unit tests, controller tests, and integration tests.
Uses Symfony test framework, bypass-finals for mocking, and parallel execution via ParaTest.

## Setup & environment

- Install: `composer install`
- Test database: `db-test` container (MariaDB) with `unittest` credentials
- Environment: `APP_ENV=test` is set automatically by test scripts
- Database setup: Test data loaded from `sql/unittest/` SQL files

## Build & tests (prefer file-scoped)

- Run single test file: `bin/phpunit tests/Path/To/FileTest.php`
- Run single test method: `bin/phpunit --filter testMethodName`
- Run unit tests only: `composer test:unit`
- Run controller tests: `composer test:controller`
- Run all tests: `composer test`
- Run parallel: `composer test:parallel`
- Coverage report: `composer test:coverage`

## Code style & conventions

- Follow PSR-12 coding standard
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

- PHPUnit docs: https://docs.phpunit.de/en/12.0/
- Symfony testing: https://symfony.com/doc/current/testing.html
- Review existing test patterns in this codebase
- Check `config/testing/` for PHPUnit configurations

## House Rules

- Unit tests must not touch the database
- Controller tests use the test database (`db-test`)
- Use `#[Group('...')]` for test categorization
- Performance tests go in `Performance/` with `#[Group('performance')]`
- Prefer `assertSame()` over `assertEquals()` for strict comparison

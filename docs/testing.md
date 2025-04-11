# Testing Guidelines

This document outlines how to write and run tests for this application.

## Testing Philosophy

We aim for a high level of test coverage to ensure code quality, prevent regressions, and facilitate refactoring and upgrades. We primarily use PHPUnit for testing.

Follow the testing pyramid principle:

*   **Unit Tests (Many):** Test individual classes and methods in isolation. Mock dependencies.
*   **Integration Tests (Fewer):** Test the interaction between several components (e.g., Service and Repository, Controller and Service).
*   **Functional Tests (Fewest):** Test complete user workflows through HTTP requests, ensuring the application works end-to-end from the user's perspective.

## Running Tests

All test commands must be executed via Docker Compose to ensure the correct environment and dependencies.

1.  **Ensure Test Environment is Ready:**
    *   Make sure your Docker containers are running (`docker compose up -d`).
    *   Set the `APP_ENV` to `test`. This is typically done via the command line when running PHPUnit.
    *   Prepare the test database (if necessary, depends on test setup):
        ```bash
        # Drop and recreate the test database
        docker compose run --rm -e APP_ENV=test app bin/console doctrine:database:drop --force --if-exists
        docker compose run --rm -e APP_ENV=test app bin/console doctrine:database:create
        docker compose run --rm -e APP_ENV=test app bin/console doctrine:migrations:migrate -n # -n for non-interactive
        # Load test fixtures if you have them
        # docker compose run --rm -e APP_ENV=test app bin/console doctrine:fixtures:load -n
        ```

2.  **Execute PHPUnit:**
    *   **Run all tests:**
        ```bash
        docker compose run --rm -e APP_ENV=test app bin/phpunit
        ```
    *   **Run tests in a specific directory:**
        ```bash
        docker compose run --rm -e APP_ENV=test app bin/phpunit tests/Service/
        ```
    *   **Run a specific test file:**
        ```bash
        docker compose run --rm -e APP_ENV=test app bin/phpunit tests/Service/MyServiceTest.php
        ```
    *   **Run a specific test method:**
        ```bash
        docker compose run --rm -e APP_ENV=test app bin/phpunit --filter testMySpecificMethod tests/Service/MyServiceTest.php
        ```
    *   **Run tests with code coverage:**
        ```bash
        # Generate HTML report in var/coverage/
        docker compose run --rm -e APP_ENV=test app bin/phpunit --coverage-html var/coverage

        # Generate Clover XML report (for CI)
        docker compose run --rm -e APP_ENV=test app bin/phpunit --coverage-clover build/logs/clover.xml
        ```
        *Note: Ensure Xdebug or PCOV is enabled in your Docker PHP container for coverage generation. Check `Dockerfile` and `php.ini`.*

3.  **Using Composer Scripts:**
    *   A convenient script might be available in `composer.json`:
        ```bash
        docker compose run --rm -e APP_ENV=test app composer test
        ```

## Writing Tests

*   **Location:** Tests should reside in the `tests/` directory, mirroring the `src/` structure (e.g., `src/Service/MyService.php` -> `tests/Service/MyServiceTest.php`).
*   **Naming:** Test classes should be named `ClassNameTest.php` and test methods should start with `test` (e.g., `testCalculateTotal`).
*   **Base Classes:** Extend appropriate Symfony base test classes:
    *   Unit Tests: `PHPUnit\Framework\TestCase`
    *   Integration/Functional Tests: `Symfony\Bundle\FrameworkBundle\Test\WebTestCase` (provides access to the container, client, etc.)
*   **Assertions:** Use PHPUnit's built-in assertions (`$this->assertEquals()`, `$this->assertTrue()`, etc.).
*   **Mocking:** Use PHPUnit's mocking capabilities (`$this->createMock()`) to isolate units under test.
*   **Data Providers:** Use data providers (`@dataProvider`) for testing methods with multiple input variations.
*   **Fixtures:** Use `doctrine/data-fixtures` for setting up predictable database states for integration and functional tests.
*   **Strict Types:** Add `declare(strict_types=1);` to all test files.
*   **Type Hinting:** Use type hints for test method parameters and return types where applicable.

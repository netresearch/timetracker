# Testing Guide

## Overview

This project uses Symfony's WebTestCase for functional testing, which provides an HTTP client to make requests to your application and inspect responses.

## Running Tests

```bash
# Run all tests
docker compose run --rm app bin/phpunit

# Run a specific test
docker compose run --rm app bin/phpunit tests/Browser/HomepageTest.php

# Run tests with specific configuration
docker compose run --rm app bin/phpunit -c phpunit.xml.dist
```

## Types of Tests

### Functional Tests

Functional tests use Symfony's built-in `WebTestCase`. They simulate HTTP requests and let you test responses without a browser.

```php
use Tests\WebTestCase;

class HomepageTest extends WebTestCase
{
    public function testHomepageLoads(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('body');
    }
}
```

### Unit Tests

Unit tests focus on testing individual components in isolation.

```php
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase
{
    public function testAdd(): void
    {
        $calculator = new Calculator();
        $this->assertEquals(4, $calculator->add(2, 2));
    }
}
```

## Common Assertions

```php
// Response assertions
$this->assertResponseIsSuccessful();
$this->assertResponseStatusCodeSame(200);
$this->assertResponseRedirects('/expected-path');

// Content assertions
$this->assertSelectorExists('h1');
$this->assertSelectorTextContains('h1', 'Expected Text');
$this->assertPageTitleContains('Expected Title');

// Form submission
$client->submitForm('Submit', [
    'form[name]' => 'John',
    'form[email]' => 'john@example.com',
]);
```

## Test Database

Tests use a separate database defined in the Docker Compose configuration and the `.env.test` file.

## Creating Tests

1. Create a test class extending `Tests\WebTestCase` for functional tests or `PHPUnit\Framework\TestCase` for unit tests
2. Add test methods prefixed with `test`
3. Use assertions to verify expected behavior

## Tips

- Keep tests focused and test one thing at a time
- Use descriptive method names: `testUserCanLoginWithValidCredentials()`
- Use data providers for testing multiple scenarios: `@dataProvider provideValidCredentials`
- Mock dependencies to isolate the code you're testing

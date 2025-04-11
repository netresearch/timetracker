# Testing Guide

## Running Tests with Coverage

To run all tests with code coverage:

```bash
docker compose run --rm -e APP_ENV=test app bin/phpunit --coverage-html ./coverage-report
```

To run specific tests with coverage:

```bash
# Test a specific controller
docker compose run --rm -e APP_ENV=test app bin/phpunit --filter=StatusController --coverage-text

# Test a specific test method
docker compose run --rm -e APP_ENV=test app bin/phpunit --filter=testDeleteAction tests/Controller/CrudControllerTest.php
```

## Identifying Untested Code

We use the `analyze-coverage.php` script to identify untested controller methods.

```bash
docker compose run --rm app php analyze-coverage.php
```

This will output all controller actions that don't have corresponding test methods.

## Controller Test Priorities

Based on analysis, focus on testing the following controller actions:

1. CrudController (high priority - core functionality)
   - deleteAction (added test)
   - saveAction
   - bulkentryAction

2. DefaultController (critical for application flow)
   - index
   - login/logout
   - getData
   - getProjects
   - getCustomers

3. InterpretationController (reporting functionality)
   - getLastEntries
   - groupByX methods

## Testing Guidelines

### 1. GET Actions Returning HTML

```php
public function testActionName(): void
{
    // Optional: Set up authenticated user if needed

    // Make the request
    $this->client->request('GET', '/route/path');

    // Assert response code
    $this->assertStatusCode(200);

    // Check content (optional but recommended)
    $response = $this->client->getResponse();
    $content = $response->getContent();
    $this->assertStringContainsString('expected content', $content);
}
```

### 2. GET Actions Returning JSON

```php
public function testActionName(): void
{
    // Make the request
    $this->client->request('GET', '/route/path');

    // Assert response code
    $this->assertStatusCode(200);

    // Check JSON structure with expected values
    $expectedJson = [
        'property1' => 'expectedValue1',
        'property2' => 'expectedValue2',
    ];

    $this->assertJsonStructure($expectedJson);
}
```

### 3. POST Actions

```php
public function testActionName(): void
{
    // Prepare test data
    $parameters = [
        'field1' => 'value1',
        'field2' => 'value2',
    ];

    // Make the request
    $this->client->request('POST', '/route/path', $parameters);

    // Assert response code
    $this->assertStatusCode(200);

    // Check response and database changes
    $this->assertJsonStructure(['success' => true]);
    $query = 'SELECT * FROM `table_name` WHERE field1 = "value1" LIMIT 1';
    $result = $this->connection->query($query)->fetchAllAssociative();
    $this->assertNotEmpty($result);
}
```

## Database Testing

When testing actions that modify the database:

1. Use a separate test database (configured in `.env.test`)
2. Create test data at the start of the test
3. Verify database changes after the action
4. Use transactions to roll back changes between tests

## Mocking External Services

When testing actions that depend on external services (like JIRA):

1. Use mocks for external service clients
2. Consider using service containers to replace actual services
3. Test error cases by simulating service failures

## Test Coverage Improvement Strategy

1. Start with small, simple controllers (e.g., StatusController)
2. Move to core functionality controllers (CrudController)
3. Test basic CRUD operations first
4. Add tests for edge cases and error conditions
5. Add tests for complex business logic later

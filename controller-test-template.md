# Controller Test Coverage Improvement Guide

## Instructions for Improving Controller Test Coverage

Based on our analysis, we need to improve test coverage across multiple controllers. Here's how to approach testing different types of controller actions:

### 1. Testing GET Actions Returning HTML

```php
public function testActionName(): void
{
    // Optional: Set up authenticated user if needed
    // $this->loginAs('admin', 'password');

    // Make the request
    $this->client->request('GET', '/route/path');

    // Assert response code
    $this->assertStatusCode(200);

    // Check content (optional but recommended)
    $crawler = $this->client->getCrawler();
    $this->assertGreaterThan(0, $crawler->filter('selector-for-expected-element')->count());
}
```

### 2. Testing GET Actions Returning JSON

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
        // You can use array_key_exists or just provide the keys for structure validation
    ];

    $this->assertJsonStructure($expectedJson);
}
```

### 3. Testing POST Actions

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

    // Check response (JSON or redirect as appropriate)
    $this->assertJsonStructure(['success' => true]);

    // Optional: Check database changes if appropriate
    $query = 'SELECT * FROM `table_name` WHERE field1 = "value1" LIMIT 1';
    $result = $this->connection->query($query)->fetchAllAssociative();
    $this->assertNotEmpty($result);
}
```

### 4. Testing Error Cases

```php
public function testActionNameWithInvalidData(): void
{
    // Prepare invalid test data
    $parameters = [
        'field1' => '', // Empty where required
        'field2' => 'value2',
    ];

    // Make the request
    $this->client->request('POST', '/route/path', $parameters);

    // Assert error response code
    $this->assertStatusCode(400); // Or whatever is appropriate (404, 500, etc.)

    // Check error message
    $this->assertMessage('Expected error message');
}
```

## Priority Controller Actions to Test

Based on our analysis, here are the main controllers and actions that need testing:

1. StatusController (simple, good starting point)
   - ✅ checkAction (already tested)
   - ✅ pageAction (now tested)

2. CrudController (high priority - core functionality)
   - deleteAction
   - saveAction
   - bulkentryAction

3. DefaultController (critical for application flow)
   - index
   - login/logout
   - getData
   - getProjects
   - getCustomers
   - etc.

4. InterpretationController (reporting functionality)
   - getLastEntries
   - groupByX methods

## Best Practices

1. Use fixtures or database setup in your `setUp()` method
2. Test both valid and invalid inputs
3. Check response status codes
4. Verify response content (HTML or JSON structure)
5. For actions that modify data, verify database changes
6. For actions with different user roles, test with different user privileges
7. When testing AJAX endpoints, ensure proper JSON structure

Remember to run tests with coverage to track your progress:

```bash
docker compose run --rm -e APP_ENV=test app bin/phpunit --coverage-html ./coverage-report
```

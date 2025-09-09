# Testing Strategy and Guidelines

**Version**: 1.0  
**Last Updated**: 2025-01-20  
**Current Coverage**: 38% → **Target Coverage**: 80%

## Table of Contents

1. [Testing Philosophy](#testing-philosophy)
2. [Test Pyramid Strategy](#test-pyramid-strategy)
3. [Testing Infrastructure](#testing-infrastructure)
4. [Unit Testing](#unit-testing)
5. [Integration Testing](#integration-testing)
6. [Functional Testing](#functional-testing)
7. [Performance Testing](#performance-testing)
8. [Security Testing](#security-testing)
9. [Test Coverage Guidelines](#test-coverage-guidelines)
10. [Best Practices](#best-practices)
11. [CI/CD Integration](#cicd-integration)

## Testing Philosophy

### Core Principles

1. **Test Behavior, Not Implementation** - Focus on what the code does, not how
2. **Fast Feedback Loop** - Tests should run quickly and frequently
3. **Isolation** - Tests should not depend on each other
4. **Repeatability** - Tests must produce consistent results
5. **Clarity** - Test failures should clearly indicate what's broken

### Testing Goals

- **Confidence**: Ensure code works as expected
- **Documentation**: Tests serve as living documentation
- **Refactoring Safety**: Enable fearless refactoring
- **Regression Prevention**: Catch bugs before production

## Test Pyramid Strategy

```
         /\
        /  \  E2E Tests (5%)
       /    \  - Critical user journeys
      /      \  - Cross-system integration
     /--------\
    /          \  Integration Tests (25%)
   /            \  - Service interactions
  /              \  - Database operations
 /                \  - External API calls
/------------------\
                     Unit Tests (70%)
                     - Business logic
                     - Utility functions
                     - Entity validation
```

### Current vs Target Distribution

| Test Type | Current | Target | Gap |
|-----------|---------|--------|-----|
| Unit | 25% | 70% | +45% |
| Integration | 10% | 25% | +15% |
| E2E | 3% | 5% | +2% |
| **Total** | **38%** | **80%** | **+42%** |

## Testing Infrastructure

### Test Environment Setup

```bash
# Install testing dependencies
composer require --dev phpunit/phpunit
composer require --dev symfony/test-pack
composer require --dev doctrine/doctrine-fixtures-bundle
composer require --dev fakerphp/faker

# Database for testing
DATABASE_TEST_URL="mysql://root:password@127.0.0.1:3306/timetracker_test"
```

### Test Database Management

```php
// tests/bootstrap.php
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Reset test database
exec('php bin/console doctrine:database:drop --force --env=test');
exec('php bin/console doctrine:database:create --env=test');
exec('php bin/console doctrine:schema:create --env=test');
```

## Unit Testing

### Service Testing Example

```php
// tests/Service/TimeCalculationServiceTest.php
namespace App\Tests\Service;

use App\Service\TimeCalculationService;
use PHPUnit\Framework\TestCase;

class TimeCalculationServiceTest extends TestCase
{
    private TimeCalculationService $service;
    
    protected function setUp(): void
    {
        $this->service = new TimeCalculationService();
    }
    
    /**
     * @dataProvider durationProvider
     */
    public function testCalculateDuration(string $start, string $end, int $expected): void
    {
        $result = $this->service->calculateDuration($start, $end);
        
        $this->assertEquals($expected, $result);
    }
    
    public function durationProvider(): array
    {
        return [
            'full_day' => ['09:00', '17:00', 480],
            'half_day' => ['09:00', '13:00', 240],
            'overnight' => ['22:00', '02:00', 240],
            'same_time' => ['09:00', '09:00', 0],
        ];
    }
    
    public function testCalculateDurationWithInvalidTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid time format');
        
        $this->service->calculateDuration('invalid', '17:00');
    }
}
```

### Entity Testing Example

```php
// tests/Entity/EntryTest.php
namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\User;
use App\Entity\Project;
use PHPUnit\Framework\TestCase;

class EntryTest extends TestCase
{
    public function testEntryCreation(): void
    {
        $entry = new Entry();
        $user = new User();
        $project = new Project();
        
        $entry->setUser($user)
              ->setProject($project)
              ->setDay(new \DateTime('2025-01-20'))
              ->setStart(new \DateTime('09:00'))
              ->setEnd(new \DateTime('17:00'))
              ->setDescription('Working on feature X');
        
        $this->assertSame($user, $entry->getUser());
        $this->assertSame($project, $entry->getProject());
        $this->assertEquals('2025-01-20', $entry->getDay()->format('Y-m-d'));
        $this->assertEquals(480, $entry->getDuration());
    }
    
    public function testEntryValidation(): void
    {
        $entry = new Entry();
        
        $violations = $this->validator->validate($entry);
        
        $this->assertCount(3, $violations); // user, day, start are required
        $this->assertEquals('This value should not be blank.', $violations[0]->getMessage());
    }
}
```

### Repository Testing Example

```php
// tests/Repository/EntryRepositoryTest.php
namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Repository\EntryRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EntryRepositoryTest extends KernelTestCase
{
    private ?EntryRepository $repository;
    
    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->repository = $kernel->getContainer()
            ->get('doctrine')
            ->getRepository(Entry::class);
    }
    
    public function testFindByDateRange(): void
    {
        // Create test data
        $this->createEntries();
        
        $entries = $this->repository->findByDateRange(
            '2025-01-01',
            '2025-01-31'
        );
        
        $this->assertCount(10, $entries);
        $this->assertContainsOnlyInstancesOf(Entry::class, $entries);
    }
    
    public function testFindByUserWithPagination(): void
    {
        $entries = $this->repository->findByUser(1, 0, 10);
        
        $this->assertLessThanOrEqual(10, count($entries));
    }
    
    public function testComplexQueryPerformance(): void
    {
        $startTime = microtime(true);
        
        $this->repository->findByDate(1, 2025, 1);
        
        $executionTime = microtime(true) - $startTime;
        $this->assertLessThan(0.1, $executionTime, 'Query took too long');
    }
}
```

## Integration Testing

### Controller Testing Example

```php
// tests/Controller/Tracking/SaveEntryActionTest.php
namespace App\Tests\Controller\Tracking;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class SaveEntryActionTest extends WebTestCase
{
    public function testSaveNewEntry(): void
    {
        $client = static::createClient();
        
        // Authenticate user
        $this->authenticateUser($client);
        
        $client->request('POST', '/tracking/save', [
            'customerId' => 1,
            'projectId' => 1,
            'activityId' => 1,
            'day' => '2025-01-20',
            'start' => '09:00',
            'end' => '17:00',
            'description' => 'Test entry',
        ]);
        
        $this->assertResponseIsSuccessful();
        $this->assertJson($client->getResponse()->getContent());
        
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertEquals('Test entry', $data['description']);
    }
    
    public function testSaveEntryWithInvalidData(): void
    {
        $client = static::createClient();
        $this->authenticateUser($client);
        
        $client->request('POST', '/tracking/save', [
            'customerId' => 999999, // Non-existent
            'start' => 'invalid',
        ]);
        
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        
        $error = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('validation', $error['message']);
    }
    
    public function testUnauthorizedAccess(): void
    {
        $client = static::createClient();
        
        $client->request('POST', '/tracking/save', []);
        
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
    
    private function authenticateUser($client): void
    {
        // Mock authentication
        $client->loginUser($this->createUser());
    }
}
```

### Service Integration Testing

```php
// tests/Service/ExportServiceIntegrationTest.php
namespace App\Tests\Service;

use App\Service\ExportService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ExportServiceIntegrationTest extends KernelTestCase
{
    private ExportService $exportService;
    
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->exportService = $container->get(ExportService::class);
        $this->loadFixtures();
    }
    
    public function testExportWithRealData(): void
    {
        $entries = $this->exportService->exportEntries(
            userId: 1,
            year: 2025,
            month: 1
        );
        
        $this->assertNotEmpty($entries);
        $this->assertContainsOnlyInstancesOf(Entry::class, $entries);
        
        // Verify eager loading (no N+1)
        $this->assertNotNull($entries[0]->getUser());
        $this->assertNotNull($entries[0]->getProject());
    }
    
    public function testBatchedExportMemoryEfficiency(): void
    {
        $initialMemory = memory_get_usage();
        
        foreach ($this->exportService->exportEntriesBatched(1, 2025, 1) as $batch) {
            $this->assertLessThanOrEqual(1000, count($batch));
            
            // Process batch
            foreach ($batch as $entry) {
                // Simulate processing
            }
        }
        
        $memoryUsed = memory_get_usage() - $initialMemory;
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsed); // < 50MB
    }
}
```

## Functional Testing

### End-to-End Testing Example

```php
// tests/Functional/TimeEntryWorkflowTest.php
namespace App\Tests\Functional;

use Symfony\Component\Panther\PantherTestCase;

class TimeEntryWorkflowTest extends PantherTestCase
{
    public function testCompleteTimeEntryWorkflow(): void
    {
        $client = static::createPantherClient();
        
        // 1. Login
        $client->request('GET', '/login');
        $client->submitForm('Login', [
            '_username' => 'testuser',
            '_password' => 'testpass',
        ]);
        
        // 2. Navigate to time entry page
        $client->clickLink('New Entry');
        
        // 3. Fill and submit entry form
        $client->submitForm('Save Entry', [
            'entry[customer]' => '1',
            'entry[project]' => '1',
            'entry[activity]' => '1',
            'entry[date]' => '2025-01-20',
            'entry[start]' => '09:00',
            'entry[end]' => '17:00',
            'entry[description]' => 'E2E test entry',
        ]);
        
        // 4. Verify success
        $this->assertSelectorTextContains('.alert-success', 'Entry saved');
        
        // 5. Verify in list
        $client->clickLink('My Entries');
        $this->assertSelectorTextContains('table', 'E2E test entry');
        
        // 6. Export and verify
        $client->clickLink('Export');
        $this->assertResponseHeaderSame('Content-Type', 
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }
}
```

## Performance Testing

### Benchmark Testing Example

```php
// tests/Performance/ExportPerformanceTest.php
namespace App\Tests\Performance;

use App\Service\ExportService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ExportPerformanceTest extends KernelTestCase
{
    public function testExportPerformanceWithLargeDataset(): void
    {
        $exportService = static::getContainer()->get(ExportService::class);
        
        // Create large dataset
        $this->createEntries(10000);
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        $entries = $exportService->exportEntries(1, 2025, 1);
        
        $executionTime = microtime(true) - $startTime;
        $memoryUsed = memory_get_usage() - $startMemory;
        
        // Performance assertions
        $this->assertLessThan(2.0, $executionTime, 'Export took too long');
        $this->assertLessThan(100 * 1024 * 1024, $memoryUsed, 'Memory usage too high');
        
        // Log metrics for trend analysis
        $this->logMetrics([
            'execution_time' => $executionTime,
            'memory_usage' => $memoryUsed,
            'entries_count' => count($entries),
        ]);
    }
    
    public function testDatabaseQueryOptimization(): void
    {
        $profiler = static::getContainer()->get('doctrine.orm.default_entity_manager')
            ->getConfiguration()
            ->getSQLLogger();
        
        $this->exportService->exportEntries(1, 2025, 1);
        
        $queries = $profiler->queries;
        
        // Assert no N+1 queries
        $this->assertLessThan(10, count($queries), 'Too many queries executed');
        
        // Check for slow queries
        foreach ($queries as $query) {
            $this->assertLessThan(0.1, $query['executionMS'], 
                'Slow query detected: ' . $query['sql']
            );
        }
    }
}
```

## Security Testing

### Security Test Examples

```php
// tests/Security/AuthenticationTest.php
namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AuthenticationTest extends WebTestCase
{
    public function testLdapInjectionPrevention(): void
    {
        $client = static::createClient();
        
        // Attempt LDAP injection
        $maliciousInput = "admin*)(uid=*))(|(uid=*";
        
        $client->request('POST', '/login', [
            '_username' => $maliciousInput,
            '_password' => 'password',
        ]);
        
        // Should fail safely without LDAP error
        $this->assertResponseStatusCodeSame(401);
        $this->assertStringNotContainsString('LDAP', 
            $client->getResponse()->getContent()
        );
    }
    
    public function testSqlInjectionPrevention(): void
    {
        $client = static::createClient();
        $this->authenticateUser($client);
        
        // Attempt SQL injection
        $client->request('GET', '/entries', [
            'filter' => "1' OR '1'='1",
        ]);
        
        $this->assertResponseIsSuccessful();
        // Should return empty or filtered results, not all records
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEmpty($data);
    }
    
    public function testCsrfProtection(): void
    {
        $client = static::createClient();
        
        // Get form with CSRF token
        $crawler = $client->request('GET', '/entry/new');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        
        // Submit with invalid token
        $client->request('POST', '/entry/save', [
            '_csrf_token' => 'invalid',
            // ... other fields
        ]);
        
        $this->assertResponseStatusCodeSame(403);
    }
    
    public function testXssProtection(): void
    {
        $client = static::createClient();
        $this->authenticateUser($client);
        
        $xssPayload = '<script>alert("XSS")</script>';
        
        $client->request('POST', '/entry/save', [
            'description' => $xssPayload,
        ]);
        
        // Retrieve and check output encoding
        $client->request('GET', '/entries');
        $content = $client->getResponse()->getContent();
        
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }
}
```

## Test Coverage Guidelines

### Priority Areas for Coverage Improvement

1. **Critical Security Components** (Target: 100%)
   - `LdapAuthenticator`
   - `TokenEncryptionService`
   - Authorization checks

2. **Core Business Logic** (Target: 90%)
   - `SaveEntryAction`
   - `ExportService`
   - `EntryRepository`

3. **Data Validation** (Target: 85%)
   - All DTOs
   - Entity validation
   - Input sanitization

4. **Integration Points** (Target: 80%)
   - JIRA integration
   - Database operations
   - External API calls

### Coverage Metrics

```bash
# Generate coverage report
composer test:coverage

# Coverage by namespace
vendor/bin/phpunit --coverage-html coverage/

# Check specific class coverage
vendor/bin/phpunit --coverage-text --filter=EntryRepository
```

### Coverage Requirements

```xml
<!-- phpunit.xml.dist -->
<coverage processUncoveredFiles="true">
    <include>
        <directory suffix=".php">src</directory>
    </include>
    <exclude>
        <directory>src/DataFixtures</directory>
        <directory>src/Kernel.php</directory>
    </exclude>
    <report>
        <html outputDirectory="coverage"/>
        <text outputFile="coverage.txt" showOnlySummary="true"/>
    </report>
</coverage>
```

## Best Practices

### Test Organization

```
tests/
├── Unit/               # Fast, isolated tests
│   ├── Service/
│   ├── Entity/
│   └── Util/
├── Integration/        # Service interactions
│   ├── Repository/
│   └── Service/
├── Functional/         # API endpoints
│   └── Controller/
├── Performance/        # Benchmarks
└── Security/          # Security tests
```

### Test Naming Convention

```php
// Method name pattern: test[Method]_[Scenario]_[ExpectedResult]

public function testCalculateDuration_WithValidTimes_ReturnsMinutes(): void
public function testSaveEntry_WithInvalidUser_ThrowsException(): void
public function testExport_WithLargeDataset_CompletesUnder2Seconds(): void
```

### Test Data Management

```php
// Use factories for test data
class EntryFactory
{
    public static function create(array $attributes = []): Entry
    {
        $faker = Factory::create();
        
        $entry = new Entry();
        $entry->setDay($attributes['day'] ?? $faker->dateTime())
              ->setStart($attributes['start'] ?? '09:00')
              ->setEnd($attributes['end'] ?? '17:00')
              ->setDescription($attributes['description'] ?? $faker->text());
        
        return $entry;
    }
    
    public static function createMany(int $count): array
    {
        return array_map(fn() => self::create(), range(1, $count));
    }
}
```

### Assertions Best Practices

```php
// Be specific with assertions
$this->assertEquals(480, $duration); // Good
$this->assertTrue($duration > 0);    // Too vague

// Use appropriate assertion methods
$this->assertCount(3, $items);              // Better than assertEquals(3, count($items))
$this->assertInstanceOf(Entry::class, $obj); // Better than assertTrue($obj instanceof Entry)
$this->assertStringContainsString('error', $message); // Better than strpos check

// Custom assertions for domain logic
$this->assertEntryIsValid($entry);
$this->assertExportContainsAllEntries($export, $entries);
```

### Test Isolation

```php
class ServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Fresh state for each test
        $this->service = new Service();
    }
    
    protected function tearDown(): void
    {
        // Clean up
        $this->service = null;
        parent::tearDown();
    }
}
```

## CI/CD Integration

### GitHub Actions Configuration

```yaml
# .github/workflows/tests.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: timetracker_test
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-interval=10s
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, xml, mysql, ldap
          coverage: xdebug
      
      - name: Install dependencies
        run: composer install --prefer-dist --no-progress
      
      - name: Run tests
        run: composer test:coverage
      
      - name: Check coverage threshold
        run: |
          coverage=$(grep "Lines:" coverage.txt | grep -o '[0-9.]*%' | head -1 | sed 's/%//')
          if (( $(echo "$coverage < 80" | bc -l) )); then
            echo "Coverage $coverage% is below 80% threshold"
            exit 1
          fi
      
      - name: Upload coverage
        uses: codecov/codecov-action@v2
        with:
          file: ./coverage.xml
```

### Pre-commit Hooks

```bash
# .git/hooks/pre-commit
#!/bin/bash

# Run tests before commit
composer test:unit

if [ $? -ne 0 ]; then
    echo "Tests failed. Commit aborted."
    exit 1
fi

# Check code coverage
coverage=$(vendor/bin/phpunit --coverage-text | grep "Lines:" | grep -o '[0-9.]*%')
threshold=80

if [ "${coverage%.*}" -lt "$threshold" ]; then
    echo "Coverage ${coverage} is below ${threshold}% threshold"
    exit 1
fi
```

## Continuous Improvement

### Metrics to Track

1. **Coverage Percentage** - Weekly improvement target: +2%
2. **Test Execution Time** - Keep under 5 minutes
3. **Flaky Test Rate** - Target: <1%
4. **Test/Code Ratio** - Target: 1.5:1

### Testing Roadmap

**Week 1-2**: Critical Security Components
- Complete `LdapAuthenticator` tests
- Add `TokenEncryptionService` tests
- Test authorization patterns

**Week 3-4**: Core Business Logic
- Increase `SaveEntryAction` coverage
- Complete `ExportService` tests
- Add repository integration tests

**Week 5-6**: Integration & E2E
- Add controller integration tests
- Implement critical E2E workflows
- Performance benchmarks

**Week 7-8**: Polish & Optimization
- Reduce test execution time
- Fix flaky tests
- Add mutation testing

### Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Symfony Testing Guide](https://symfony.com/doc/current/testing.html)
- [Test Driven Development](https://www.amazon.com/Test-Driven-Development-Kent-Beck/dp/0321146530)
- [Growing Object-Oriented Software](http://www.growing-object-oriented-software.com/)

---

**Remember**: Tests are not a burden, they're an investment in code quality and team confidence. Every test you write today saves debugging time tomorrow! 🚀
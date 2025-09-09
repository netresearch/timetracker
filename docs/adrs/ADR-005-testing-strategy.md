# ADR-005: Testing Strategy

## Status
Accepted

## Context and Problem Statement
The timetracker application had insufficient test coverage and quality:
- **Current Coverage**: 38% (target: 80%+)
- **Test Failures**: 23 failing tests requiring systematic resolution
- **Test Structure**: Mixed unit and integration tests without clear separation
- **Quality Issues**: Tests tightly coupled to implementation details
- **CI/CD**: Inconsistent test execution and reporting

The application needed a comprehensive testing strategy to:
- Improve reliability and confidence in deployments
- Support refactoring and architectural changes
- Ensure performance requirements are met
- Prevent regression bugs
- Enable safe continuous deployment

## Decision Drivers
- **Quality Assurance**: Achieve >80% test coverage with meaningful tests
- **Regression Prevention**: Catch bugs before production deployment
- **Refactoring Safety**: Enable safe code refactoring and architectural changes
- **Performance Validation**: Ensure performance requirements are met
- **Developer Productivity**: Fast, reliable test feedback during development

## Considered Options

### Option 1: Minimal Testing (Current - Rejected)
**Pros:**
- Low initial effort
- Fast development in short term
- Simple test suite

**Cons:**
- High bug risk in production
- Difficult to refactor safely
- Manual testing overhead
- No performance validation

### Option 2: 100% Unit Test Coverage (Rejected)
**Pros:**
- Maximum code coverage
- Fast test execution
- Excellent isolation

**Cons:**
- Over-testing implementation details
- Brittle tests that break with refactoring
- Missing integration issues
- High maintenance overhead

### Option 3: Balanced Testing Strategy (Chosen)
**Pros:**
- Appropriate test coverage for each layer
- Balance between speed and confidence
- Supports architectural changes
- Performance validation included

**Cons:**
- More complex test suite
- Requires discipline to maintain
- Initial setup effort

## Decision Outcome
Implement comprehensive testing strategy with:

1. **Test Pyramid**: Unit → Integration → End-to-End
2. **Parallel Execution**: Fast feedback with optimal resource usage
3. **Performance Testing**: Automated performance regression detection
4. **Test Separation**: Clear boundaries between test types
5. **Quality Gates**: Coverage and performance thresholds

## Testing Architecture

### Test Pyramid Structure

```
                     ┌─────────────────┐
                     │   E2E Tests     │  5% - User journeys
                     │   (Playwright)  │
                     └─────────────────┘
                  ┌─────────────────────────┐
                  │  Integration Tests      │  25% - API endpoints
                  │  (Controller Tests)     │       Service integration
                  └─────────────────────────┘
            ┌───────────────────────────────────────┐
            │           Unit Tests                  │  70% - Business logic
            │  (Services, Repositories, Entities)  │       Individual classes
            └───────────────────────────────────────┘
```

### Test Suite Organization

```
tests/
├── Unit/                           # Fast, isolated unit tests
│   ├── Service/                    # Business logic testing
│   ├── Repository/                 # Data access layer testing  
│   ├── Entity/                     # Domain model testing
│   └── Security/                   # Authentication/authorization
├── Controller/                     # Integration tests
│   ├── Admin/                      # Admin functionality
│   ├── Default/                    # User functionality
│   └── Integration/                # API integration tests
├── Performance/                    # Performance testing
│   ├── PerformanceBenchmark.php    # Automated benchmarks
│   └── LoadTest.php                # Load testing scenarios
└── bootstrap.php                   # Test environment setup
```

## Testing Strategy by Layer

### Unit Tests (70% of test suite)

#### Service Layer Testing
```php
class TimeCalculationServiceTest extends TestCase
{
    private TimeCalculationService $service;
    
    protected function setUp(): void
    {
        $this->service = new TimeCalculationService();
    }
    
    public function testFormatDuration(): void
    {
        // Test business logic in isolation
        $this->assertEquals('1:30', $this->service->formatDuration(90));
        $this->assertEquals('0:45', $this->service->formatDuration(45));
    }
    
    public function testCalculateDifference(): void
    {
        $result = $this->service->calculateDifference('09:00', '17:30');
        $this->assertEquals(510, $result); // 8.5 hours in minutes
    }
}
```

#### Repository Testing with Database
```php
class EntryRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private EntryRepository $repository;
    
    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')->getManager();
        $this->repository = $this->entityManager
            ->getRepository(Entry::class);
    }
    
    public function testFindByRecentDaysOfUser(): void
    {
        // Use test database with fixtures
        $user = $this->createTestUser();
        $entries = $this->repository->findByRecentDaysOfUser($user, 3);
        
        $this->assertGreaterThan(0, count($entries));
        $this->assertInstanceOf(Entry::class, $entries[0]);
    }
}
```

### Integration Tests (25% of test suite)

#### Controller Testing
```php
class DefaultControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    
    protected function setUp(): void
    {
        $this->client = static::createClient();
    }
    
    public function testGetSummary(): void
    {
        $this->loginAsUser('testuser');
        
        $this->client->request('GET', '/api/summary', [
            'customer' => 1,
            'project' => 1,
            'datestart' => '2024-01-01',
            'dateend' => '2024-12-31'
        ]);
        
        $this->assertResponseIsSuccessful();
        $this->assertJsonStructure([
            'customer',
            'project', 
            'activity',
            'ticket'
        ]);
    }
}
```

#### Database Integration Testing
```php
class EntryQueryServiceIntegrationTest extends KernelTestCase
{
    public function testComplexQueryWithJoins(): void
    {
        $service = self::getContainer()->get(EntryQueryService::class);
        
        $result = $service->getEntriesWithSummary([
            'user_id' => 1,
            'date_from' => '2024-01-01',
            'date_to' => '2024-12-31'
        ]);
        
        $this->assertArrayHasKey('entries', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertGreaterThan(0, $result['summary']['total_duration']);
    }
}
```

### Performance Tests (5% of test suite)

#### Automated Performance Benchmarks
```php
class PerformanceBenchmarkTest extends KernelTestCase
{
    /**
     * @group performance
     */
    public function testDashboardPerformance(): void
    {
        $start = microtime(true);
        
        $service = self::getContainer()->get(DashboardService::class);
        $result = $service->getDashboardData(1); // User ID 1
        
        $duration = (microtime(true) - $start) * 1000; // Convert to ms
        
        $this->assertLessThan(250, $duration, 'Dashboard should load in <250ms');
        $this->assertArrayHasKey('entries', $result);
        $this->assertArrayHasKey('summary', $result);
    }
    
    /**
     * @group performance
     */
    public function testLargeDatasetExport(): void
    {
        $this->createLargeDataset(10000); // Create 10k entries
        
        $start = microtime(true);
        $memoryStart = memory_get_usage(true);
        
        $service = self::getContainer()->get(ExportService::class);
        $generator = $service->exportLargeDataset(['limit' => 10000]);
        
        $count = 0;
        foreach ($generator as $entry) {
            $count++;
            if ($count % 1000 === 0) {
                $this->assertLessThan(
                    512 * 1024 * 1024, // 512MB
                    memory_get_usage(true),
                    'Memory usage should stay below 512MB'
                );
            }
        }
        
        $duration = (microtime(true) - $start) * 1000;
        $this->assertLessThan(15000, $duration, 'Export should complete in <15s');
        $this->assertEquals(10000, $count, 'Should export all entries');
    }
}
```

## Test Execution Strategy

### Parallel Test Execution

#### PHPUnit Configuration
```xml
<!-- phpunit.xml -->
<phpunit>
    <testsuites>
        <testsuite name="unit-parallel">
            <directory>tests/Unit</directory>
            <directory>tests/Service</directory>
            <directory>tests/Repository</directory>
        </testsuite>
        <testsuite name="controller-sequential">
            <directory>tests/Controller</directory>
        </testsuite>
        <testsuite name="performance">
            <directory>tests/Performance</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

#### Parallel Execution with ParaTest
```bash
# Fast parallel unit tests
composer test:parallel:unit
# paratest --processes=8 --testsuite=unit-parallel

# Sequential integration tests
composer test:controller  
# phpunit --testsuite=controller-sequential

# Performance testing
composer perf:benchmark
```

### Test Database Management

#### Test Database Configuration
```yaml
# .env.test
DATABASE_URL="mysql://timetracker:password@127.0.0.1:3306/timetracker_test"

# Separate test database for isolation
doctrine:
    dbal:
        connections:
            test:
                driver: pdo_mysql
                url: '%env(DATABASE_URL)%'
                charset: utf8mb4
```

#### Test Data Management
```php
class DatabaseTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        $this->loadFixtures([
            UserFixture::class,
            CustomerFixture::class,
            ProjectFixture::class,
            ActivityFixture::class,
            EntryFixture::class
        ]);
    }
    
    protected function tearDown(): void
    {
        $this->cleanDatabase();
    }
}
```

## Test Quality Standards

### Coverage Requirements
- **Overall Coverage**: >80%
- **Service Layer**: >90%
- **Repository Layer**: >85%
- **Controller Layer**: >70%
- **Entity Layer**: >60%

### Test Quality Metrics
```php
// Example of comprehensive test
class EntryServiceTest extends TestCase
{
    public function testCreateEntry(): void
    {
        // Arrange
        $user = $this->createMock(User::class);
        $entryData = ['day' => '2024-01-01', 'start' => '09:00'];
        
        // Act
        $entry = $this->entryService->createEntry($user, $entryData);
        
        // Assert - Multiple assertions for thorough validation
        $this->assertInstanceOf(Entry::class, $entry);
        $this->assertEquals('2024-01-01', $entry->getDay());
        $this->assertEquals('09:00', $entry->getStart());
        $this->assertSame($user, $entry->getUser());
        
        // Verify side effects
        $this->assertTrue($entry->isPersisted());
    }
}
```

### Performance Test Thresholds
```php
class PerformanceThresholds
{
    public const MAX_RESPONSE_TIME = 250;      // milliseconds
    public const MAX_MEMORY_USAGE = 512;      // MB
    public const MIN_CACHE_HIT_RATIO = 0.7;   // 70%
    public const MAX_DATABASE_QUERIES = 10;    // per request
}
```

## Test Automation and CI/CD

### GitHub Actions Workflow
```yaml
# .github/workflows/tests.yml
name: Test Suite

on: [push, pull_request]

jobs:
  unit-tests:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: [8.4]
    steps:
      - uses: actions/checkout@v3
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: ldap, pdo_mysql
      
      - name: Install dependencies
        run: composer install --no-interaction
      
      - name: Run unit tests
        run: composer test:parallel:unit
        
      - name: Generate coverage
        run: composer test:coverage-text
        
  integration-tests:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: timetracker_test
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3
          
    steps:
      - name: Run controller tests
        run: composer test:controller
        
  performance-tests:
    runs-on: ubuntu-latest
    if: github.event_name == 'push' && github.ref == 'refs/heads/main'
    steps:
      - name: Run performance benchmarks
        run: composer perf:benchmark
```

### Quality Gates
```yaml
# Quality gate configuration
quality_gates:
  coverage:
    threshold: 80
    fail_on_decrease: true
  performance:
    response_time_95th: 250ms
    memory_usage_max: 512MB
    database_queries_max: 10
```

## Test Environment Management

### Docker Test Environment
```yaml
# docker-compose.test.yml
version: '3.8'
services:
  app-test:
    build: .
    environment:
      APP_ENV: test
      DATABASE_URL: mysql://timetracker:password@db_unittest:3306/timetracker_test
    depends_on:
      - db_unittest
      
  db_unittest:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: timetracker_test
      MYSQL_USER: timetracker
      MYSQL_PASSWORD: password
    ports:
      - "3307:3306"
```

### Test Data Fixtures
```php
class EntryFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $user = $this->getReference('user-1');
        $project = $this->getReference('project-1');
        
        for ($i = 1; $i <= 100; $i++) {
            $entry = new Entry();
            $entry->setUser($user)
                  ->setProject($project)
                  ->setDay(new DateTime("-{$i} days"))
                  ->setStart('09:00')
                  ->setEnd('17:30')
                  ->setDuration(510);
            
            $manager->persist($entry);
        }
        
        $manager->flush();
    }
}
```

## Test Monitoring and Reporting

### Test Metrics Dashboard
- Test execution times
- Coverage trends
- Flaky test identification
- Performance regression tracking

### Automated Reporting
```bash
# Generate comprehensive test reports
composer test:report
# Generates:
# - Coverage report (HTML)
# - Performance benchmark results
# - Test execution summary
# - Failed test analysis
```

## Migration Strategy

### Phase 1: Foundation (Completed)
- [x] Test environment setup
- [x] Basic unit test structure
- [x] CI/CD pipeline configuration
- [x] Coverage measurement

### Phase 2: Test Coverage Improvement (In Progress)
- [ ] Service layer tests (90% target)
- [ ] Repository tests with database (85% target)
- [ ] Controller integration tests (70% target)
- [ ] Performance test implementation

### Phase 3: Advanced Testing (Planned)
- [ ] End-to-end testing with Playwright
- [ ] Load testing automation
- [ ] Performance regression detection
- [ ] Mutation testing for test quality

## Related ADRs
- ADR-001: Service Layer Pattern Implementation
- ADR-002: Repository Pattern Refactoring
- ADR-004: Performance Optimization Strategy

## References
- [Test Pyramid - Martin Fowler](https://martinfowler.com/articles/practical-test-pyramid.html)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Symfony Testing](https://symfony.com/doc/current/testing.html)
- [ParaTest Parallel Testing](https://github.com/paratestphp/paratest)
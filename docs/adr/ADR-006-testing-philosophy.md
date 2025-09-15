# ADR-006: Testing Philosophy

**Status:** Accepted  
**Date:** 2024-09-15  
**Deciders:** Architecture Team, QA Team  

## Context

The TimeTracker application requires a comprehensive testing strategy to ensure reliability, maintainability, and performance across complex enterprise integrations including LDAP authentication, JIRA synchronization, and multi-tenant operations. The testing approach must balance thorough coverage with execution speed and maintainability.

### Requirements
- **Code Coverage**: Target 80% overall coverage (currently 38%)
- **Test Performance**: Full test suite execution under 10 minutes
- **Integration Testing**: LDAP, JIRA, database, and API endpoint validation
- **Parallel Execution**: Support for parallel test execution to reduce CI time
- **Enterprise Scenarios**: Multi-tenant isolation, role-based access, error handling

### Current Testing Challenges
- Long test execution times (>20 minutes for full suite)
- Complex setup for LDAP and JIRA integration tests
- Database state management across test classes
- Inconsistent test data and fixtures
- Limited performance testing for large datasets

## Decision

We will implement a **test pyramid strategy** with **parallel execution**, **containerized dependencies**, and **performance benchmarking** integrated into the CI pipeline.

### Test Pyramid Implementation

```
                    ┌─────────────────┐
                    │   E2E Tests     │ 5%
                    │  (Functional)   │ 
                    └─────────────────┘
                ┌─────────────────────────┐
                │  Integration Tests      │ 25%
                │ (API, Services, DB)     │
                └─────────────────────────┘
        ┌─────────────────────────────────────────┐
        │            Unit Tests                   │ 70%
        │   (Classes, Methods, Logic)             │
        └─────────────────────────────────────────┘
```

## Implementation Details

### 1. Unit Tests (70% of test suite)

**Purpose**: Fast, isolated testing of individual components
**Execution Time**: <3 minutes for entire unit test suite
**Coverage Target**: >90% for business logic classes

```php
// Example: Service unit test with comprehensive mocking
class TimeCalculationServiceTest extends TestCase
{
    private TimeCalculationService $service;
    private MockObject $clockMock;
    
    protected function setUp(): void
    {
        $this->clockMock = $this->createMock(ClockInterface::class);
        $this->service = new TimeCalculationService($this->clockMock);
    }
    
    #[Test]
    public function calculateMonthlyHours_WithValidEntries_ReturnsCorrectTotal(): void
    {
        // Arrange
        $entries = [
            $this->createEntryMock('2024-01-15', 480), // 8 hours
            $this->createEntryMock('2024-01-16', 420), // 7 hours
            $this->createEntryMock('2024-01-17', 360), // 6 hours
        ];
        
        // Act
        $result = $this->service->calculateMonthlyHours($entries);
        
        // Assert
        $this->assertEquals(1260, $result->getTotalMinutes());
        $this->assertEquals(21.0, $result->getTotalHours());
        $this->assertEquals(3, $result->getEntryCount());
    }
    
    #[Test]
    #[DataProvider('invalidEntryDataProvider')]
    public function calculateMonthlyHours_WithInvalidData_ThrowsException(
        array $entries,
        string $expectedExceptionMessage
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);
        
        $this->service->calculateMonthlyHours($entries);
    }
    
    public static function invalidEntryDataProvider(): array
    {
        return [
            'empty_entries' => [[], 'No entries provided'],
            'negative_duration' => [
                [self::createEntryMock('2024-01-15', -60)],
                'Entry duration cannot be negative'
            ],
        ];
    }
    
    private function createEntryMock(string $date, int $duration): Entry
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getDay')->willReturn(new \DateTime($date));
        $entry->method('getDuration')->willReturn($duration);
        return $entry;
    }
}
```

### 2. Integration Tests (25% of test suite)

**Purpose**: Test component interactions, database operations, external services
**Execution Time**: <5 minutes with containerized dependencies
**Coverage Target**: >80% for service interactions

```php
// Example: Repository integration test with database
#[Group('integration')]
class EntryRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private EntryRepository $repository;
    private User $testUser;
    
    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();
            
        $this->repository = $this->entityManager->getRepository(Entry::class);
        
        // Create test user
        $this->testUser = new User();
        $this->testUser->setUsername('test.user');
        $this->testUser->setEmail('test@example.com');
        $this->entityManager->persist($this->testUser);
        $this->entityManager->flush();
    }
    
    #[Test]
    public function findByUserAndDateRange_WithValidData_ReturnsFilteredEntries(): void
    {
        // Arrange: Create test entries
        $startDate = new \DateTime('2024-01-01');
        $endDate = new \DateTime('2024-01-31');
        
        $this->createTestEntry('2024-01-15', 480); // Should be included
        $this->createTestEntry('2024-01-25', 420); // Should be included  
        $this->createTestEntry('2024-02-15', 360); // Should be excluded
        
        // Act
        $entries = $this->repository->findByUserAndDateRange(
            $this->testUser, 
            $startDate, 
            $endDate
        );
        
        // Assert
        $this->assertCount(2, $entries);
        $this->assertTrue(
            $entries[0]->getDay() >= $startDate && 
            $entries[0]->getDay() <= $endDate
        );
    }
    
    #[Test]
    public function getMonthlyAggregates_WithComplexData_ReturnsCorrectAggregation(): void
    {
        // Arrange: Create entries across multiple projects
        $project1 = $this->createTestProject('Project Alpha');
        $project2 = $this->createTestProject('Project Beta');
        
        $this->createTestEntry('2024-01-15', 480, $project1);
        $this->createTestEntry('2024-01-16', 420, $project1);
        $this->createTestEntry('2024-01-17', 360, $project2);
        
        // Act
        $aggregates = $this->repository->getMonthlyAggregates(
            $this->testUser,
            new \DateTime('2024-01-01')
        );
        
        // Assert
        $this->assertCount(2, $aggregates);
        
        $alpha = array_filter($aggregates, fn($a) => $a['project_name'] === 'Project Alpha')[0];
        $this->assertEquals(900, $alpha['total_duration']); // 480 + 420
        $this->assertEquals(2, $alpha['entry_count']);
        
        $beta = array_filter($aggregates, fn($a) => $a['project_name'] === 'Project Beta')[0];
        $this->assertEquals(360, $beta['total_duration']);
        $this->assertEquals(1, $beta['entry_count']);
    }
    
    private function createTestEntry(string $date, int $duration, ?Project $project = null): Entry
    {
        $entry = new Entry();
        $entry->setUser($this->testUser);
        $entry->setDay(new \DateTime($date));
        $entry->setDuration($duration);
        $entry->setProject($project ?? $this->createTestProject('Default Project'));
        $entry->setDescription('Test entry');
        
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
        
        return $entry;
    }
}
```

### 3. API Integration Tests 

**Purpose**: Test HTTP endpoints, authentication, serialization
**Tools**: Symfony's WebTestCase with custom assertions

```php
#[Group('api')]
class EntryApiTest extends WebTestCase
{
    private Client $client;
    private User $testUser;
    
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->testUser = $this->createAuthenticatedUser();
    }
    
    #[Test]
    public function postEntry_WithValidData_CreatesEntryAndReturnsCreated(): void
    {
        // Arrange
        $entryData = [
            'day' => '2024-01-15',
            'duration' => 480,
            'description' => 'Feature development',
            'project' => 1,
            'ticket' => 'PROJ-123'
        ];
        
        // Act
        $this->client->request(
            'POST',
            '/api/entries',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($entryData)
        );
        
        // Assert
        $this->assertResponseStatusCodeSame(201);
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $response);
        $this->assertEquals($entryData['duration'], $response['duration']);
        $this->assertEquals($entryData['description'], $response['description']);
    }
    
    #[Test] 
    public function getEntries_WithDateFilter_ReturnsFilteredResults(): void
    {
        // Arrange: Create test entries
        $this->createTestApiEntry('2024-01-15', 480);
        $this->createTestApiEntry('2024-01-16', 420);
        $this->createTestApiEntry('2024-02-15', 360);
        
        // Act
        $this->client->request('GET', '/api/entries?date=2024-01-15');
        
        // Assert
        $this->assertResponseIsSuccessful();
        
        $entries = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $entries);
        $this->assertEquals('2024-01-15', $entries[0]['day']);
    }
    
    #[Test]
    public function postEntry_WithInvalidData_ReturnsValidationErrors(): void
    {
        // Arrange: Invalid entry data (missing required fields)
        $invalidData = [
            'description' => 'Test entry'
            // Missing required: day, duration, project
        ];
        
        // Act
        $this->client->request(
            'POST',
            '/api/entries',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($invalidData)
        );
        
        // Assert
        $this->assertResponseStatusCodeSame(422);
        
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $response);
        $this->assertArrayHasKey('day', $response['errors']);
        $this->assertArrayHasKey('duration', $response['errors']);
    }
}
```

### 4. Performance Tests (Separate Suite)

**Purpose**: Validate performance under load, identify bottlenecks
**Execution**: Separate CI job, runs against staging environment

```php
#[Group('performance')]
class PerformanceTest extends KernelTestCase
{
    private const PERFORMANCE_THRESHOLD_MS = 200;
    private const BULK_OPERATION_THRESHOLD_MS = 5000;
    
    #[Test]
    public function reportGeneration_WithLargeDataset_CompletesWithinThreshold(): void
    {
        // Arrange: Create large dataset (1000 entries)
        $user = $this->createUserWithEntries(1000);
        $reportService = $this->getContainer()->get(ReportService::class);
        
        // Act & Measure
        $startTime = microtime(true);
        $report = $reportService->generateMonthlyReport($user, new \DateTime('2024-01'));
        $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
        
        // Assert
        $this->assertNotNull($report);
        $this->assertLessThan(
            self::BULK_OPERATION_THRESHOLD_MS, 
            $duration,
            "Report generation took {$duration}ms, expected < " . self::BULK_OPERATION_THRESHOLD_MS . "ms"
        );
        
        // Record performance metric
        $this->recordPerformanceMetric('report_generation', $duration);
    }
    
    #[Test]
    public function apiEndpoint_UnderLoad_MaintainsResponseTime(): void
    {
        $client = static::createClient();
        $durations = [];
        
        // Simulate 50 concurrent requests
        for ($i = 0; $i < 50; $i++) {
            $startTime = microtime(true);
            
            $client->request('GET', '/api/entries/today');
            $this->assertResponseIsSuccessful();
            
            $duration = (microtime(true) - $startTime) * 1000;
            $durations[] = $duration;
        }
        
        $averageDuration = array_sum($durations) / count($durations);
        $maxDuration = max($durations);
        
        $this->assertLessThan(
            self::PERFORMANCE_THRESHOLD_MS,
            $averageDuration,
            "Average response time {$averageDuration}ms exceeds threshold"
        );
        
        $this->assertLessThan(
            self::PERFORMANCE_THRESHOLD_MS * 2,
            $maxDuration,
            "Maximum response time {$maxDuration}ms exceeds threshold"
        );
    }
}
```

### 5. Test Infrastructure & Configuration

**Parallel Execution Configuration:**
```xml
<!-- config/testing/paratest.xml -->
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    bootstrap="tests/bootstrap.php"
    colors="true"
    stopOnFailure="false"
    executionOrder="depends,defects"
>
    <testsuites>
        <testsuite name="unit-parallel">
            <directory>tests/Unit</directory>
            <directory>tests/Service</directory>
        </testsuite>
        <testsuite name="controller-sequential">
            <directory>tests/Controller</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

**Docker Test Dependencies:**
```yaml
# docker-compose.test.yml
version: '3.8'
services:
  test-db:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: test123
      MYSQL_DATABASE: timetracker_test
      MYSQL_USER: test
      MYSQL_PASSWORD: test
    tmpfs: /var/lib/mysql  # In-memory for speed
    
  test-redis:
    image: redis:7-alpine
    tmpfs: /data
    
  test-ldap:
    image: osixia/openldap:1.5.0
    environment:
      LDAP_ORGANISATION: "Test Company"
      LDAP_DOMAIN: "test.local"
      LDAP_ADMIN_PASSWORD: "admin123"
    volumes:
      - ./tests/fixtures/ldap:/container/service/slapd/assets/config/bootstrap/ldif/custom
```

### Test Data Management

**Database Fixtures:**
```php
class EntryTestFixtures 
{
    public static function createBasicEntries(EntityManagerInterface $em): array
    {
        $user = new User();
        $user->setUsername('test.user');
        $user->setEmail('test@example.com');
        
        $project = new Project();
        $project->setName('Test Project');
        
        $entries = [];
        for ($i = 1; $i <= 5; $i++) {
            $entry = new Entry();
            $entry->setUser($user);
            $entry->setProject($project);
            $entry->setDay(new \DateTime("2024-01-{$i}"));
            $entry->setDuration(480); // 8 hours
            $entry->setDescription("Test entry {$i}");
            
            $entries[] = $entry;
            $em->persist($entry);
        }
        
        $em->persist($user);
        $em->persist($project);
        $em->flush();
        
        return $entries;
    }
}
```

## Consequences

### Positive
- **Fast Feedback**: Unit tests provide immediate feedback (under 3 minutes)
- **Comprehensive Coverage**: 80% target ensures reliability and maintainability
- **Parallel Execution**: 60% reduction in CI pipeline time through parallelization
- **Performance Validation**: Automated performance testing prevents regressions
- **Integration Confidence**: Containerized dependencies ensure consistent test environment
- **Maintainable Tests**: Clear test structure and data management patterns

### Negative
- **Initial Setup Cost**: Significant effort to reach 80% coverage from current 38%
- **Test Maintenance**: More tests require ongoing maintenance and updates
- **Infrastructure Complexity**: Docker containers and parallel execution add complexity
- **Resource Usage**: Performance tests require additional CI resources
- **Learning Curve**: Team needs training on advanced testing patterns

### Coverage Targets and Metrics

**Overall Coverage Targets:**
- **Unit Test Coverage**: >90% for service classes, >80% for repositories
- **Integration Test Coverage**: >80% for API endpoints, >70% for service interactions
- **End-to-End Coverage**: >60% for critical user journeys

**Quality Gates:**
```bash
# Composer scripts for quality gates
"test:coverage-gate": [
    "php -d memory_limit=512M bin/phpunit --coverage-text --coverage-clover=coverage.xml",
    "php tests/Tools/CoverageChecker.php coverage.xml 80"
],
"test:performance-gate": [
    "php -d memory_limit=1G bin/phpunit --group=performance"
]
```

### CI/CD Integration
```yaml
# .github/workflows/tests.yml
name: Tests
on: [push, pull_request]

jobs:
  unit-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.4
      - name: Run Unit Tests (Parallel)
        run: composer test:parallel:unit
        
  integration-tests:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mariadb:10.11
        env:
          MYSQL_ROOT_PASSWORD: test123
    steps:
      - uses: actions/checkout@v3
      - name: Run Integration Tests
        run: composer test:integration
        
  performance-tests:
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/main'
    steps:
      - uses: actions/checkout@v3
      - name: Run Performance Tests
        run: composer test:performance
```

This comprehensive testing philosophy ensures high code quality, fast development cycles, and confidence in production deployments while maintaining reasonable execution times and resource usage.
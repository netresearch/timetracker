# Testing Strategy & Framework

**Comprehensive testing approach for TimeTracker application ensuring reliability, performance, and security**

---

## Table of Contents

1. [Testing Philosophy](#testing-philosophy)
2. [Test Architecture](#test-architecture)
3. [Testing Environments](#testing-environments)
4. [Unit Testing](#unit-testing)
5. [Integration Testing](#integration-testing)
6. [E2E & Browser Testing](#e2e--browser-testing)
7. [Performance Testing](#performance-testing)
8. [Security Testing](#security-testing)
9. [CI/CD Integration](#cicd-integration)
10. [Troubleshooting Tests](#troubleshooting-tests)

---

## Testing Philosophy

### Core Principles

1. **Test Pyramid**: 70% Unit, 25% Integration, 5% E2E
2. **Fail Fast**: Quick feedback loops for developers
3. **Isolation**: Tests don't depend on each other
4. **Repeatability**: Consistent results across environments
5. **Coverage**: Meaningful coverage over percentage targets

### Quality Gates

| Gate | Requirement | Impact |
|------|-------------|---------|
| **Unit Tests** | >80% coverage | Blocks PR merge |
| **Integration** | All pass | Blocks deployment |
| **Performance** | <2s API response | Warning |
| **Security** | No HIGH vulnerabilities | Blocks deployment |
| **Code Quality** | PHPStan Level 9 | Blocks PR merge |

---

## Test Architecture

### Test Structure

```
tests/
├── 🔬 Unit/                    # Fast isolated tests (70%)
│   ├── Service/               # Business logic tests  
│   ├── Repository/            # Data access tests
│   ├── Entity/                # Model validation tests
│   ├── Util/                  # Utility function tests
│   └── Security/              # Auth/encryption tests
├── 🔗 Integration/             # Service interaction tests (25%)
│   ├── Database/              # DB integration tests
│   ├── LDAP/                  # Authentication integration
│   ├── JIRA/                  # External API integration
│   └── Export/                # File generation tests
├── 🌐 Controller/              # API endpoint tests (5%)
│   ├── Auth/                  # Authentication endpoints
│   ├── Tracking/              # Time entry endpoints
│   └── Admin/                 # Management endpoints
├── ⚡ Performance/             # Load and speed tests
│   ├── Benchmarks/            # Automated benchmarks
│   ├── LoadTests/             # Concurrent user simulation
│   └── MemoryTests/           # Memory usage validation
└── 🛡️ Security/               # Security validation tests
    ├── OWASP/                 # Security vulnerability tests
    ├── Penetration/           # Automated pen testing
    └── Compliance/            # GDPR/audit tests
```

### Test Configuration

**PHPUnit Configuration** (`phpunit.xml.dist`):
```xml
<phpunit bootstrap="tests/bootstrap.php" 
         colors="true"
         executionOrder="depends,defects"
         cacheDirectory=".phpunit.cache">
    
    <coverage>
        <include>
            <directory suffix=".php">src</directory>
        </include>
        <exclude>
            <directory>src/Migrations</directory>
        </exclude>
        <report>
            <html outputDirectory="var/coverage" lowUpperBound="50" highLowerBound="80"/>
            <text outputFile="php://stdout" showUncoveredFiles="false"/>
        </report>
    </coverage>
    
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="controller">
            <directory>tests/Controller</directory>
        </testsuite>
        <testsuite name="performance">
            <directory>tests/Performance</directory>
        </testsuite>
    </testsuites>
    
    <php>
        <server name="APP_ENV" value="test" force="true"/>
        <ini name="memory_limit" value="2G"/>
    </php>
</phpunit>
```

---

## Testing Environments

### Environment Configuration

| Environment | Purpose | Database | External APIs |
|-------------|---------|----------|---------------|
| **Unit** | Fast isolated tests | In-memory SQLite | Mocked |
| **Integration** | Service interaction | Test MySQL | Stubbed |
| **E2E** | Full application | Dedicated DB | Real/Staging |
| **Performance** | Load testing | Production-like | Production-like |

### Test Database Setup

```bash
# Automated test database setup
make reset-test-db

# Manual setup
export APP_ENV=test
php bin/console doctrine:database:drop --force --if-exists
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --group=test --no-interaction
```

### Environment Variables

```env
# .env.test
APP_ENV=test
APP_DEBUG=0
DATABASE_URL="mysql://unittest:unittest@127.0.0.1:3307/unittest"

# Disable external integrations in tests
LDAP_HOST=mock://ldap.test
JIRA_INTEGRATION_ENABLED=false
WEBHOOK_DELIVERY_ENABLED=false

# Test-specific settings
SYMFONY_DEPRECATIONS_HELPER=weak
KERNEL_CLASS=App\Kernel
```

---

## Unit Testing

### Service Testing

**Example: Time Entry Validation Service**

```php
<?php
// tests/Unit/Service/EntryValidationServiceTest.php

namespace Tests\Unit\Service;

use App\Service\EntryValidationService;
use App\Entity\Entry;
use App\Repository\EntryRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class EntryValidationServiceTest extends TestCase
{
    private EntryValidationService $service;
    private MockObject $entryRepository;
    
    protected function setUp(): void
    {
        $this->entryRepository = $this->createMock(EntryRepository::class);
        $this->service = new EntryValidationService($this->entryRepository);
    }
    
    /** @test */
    public function it_validates_non_overlapping_entries(): void
    {
        // Arrange
        $entry = new Entry();
        $entry->setStart(new \DateTime('09:00'));
        $entry->setEnd(new \DateTime('17:00'));
        $entry->setDay(new \DateTime('2024-01-15'));
        
        $this->entryRepository
            ->method('findOverlappingEntries')
            ->willReturn([]);
        
        // Act
        $result = $this->service->validateEntry($entry);
        
        // Assert
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getViolations());
    }
    
    /** @test */
    public function it_detects_overlapping_entries(): void
    {
        // Arrange
        $entry = new Entry();
        $entry->setStart(new \DateTime('14:00'));
        $entry->setEnd(new \DateTime('18:00'));
        
        $overlappingEntry = new Entry();
        $overlappingEntry->setId(123);
        $overlappingEntry->setStart(new \DateTime('13:00'));
        $overlappingEntry->setEnd(new \DateTime('16:00'));
        
        $this->entryRepository
            ->method('findOverlappingEntries')
            ->willReturn([$overlappingEntry]);
        
        // Act
        $result = $this->service->validateEntry($entry);
        
        // Assert
        $this->assertFalse($result->isValid());
        $violations = $result->getViolations();
        $this->assertCount(1, $violations);
        $this->assertStringContains('overlapping', $violations[0]->getMessage());
    }
    
    /** @test */
    public function it_validates_maximum_daily_hours(): void
    {
        // Arrange
        $entry = new Entry();
        $entry->setDuration(720); // 12 hours
        $entry->setDay(new \DateTime('2024-01-15'));
        
        $existingEntry = new Entry();
        $existingEntry->setDuration(240); // 4 hours
        
        $this->entryRepository
            ->method('findByUserAndDate')
            ->willReturn([$existingEntry]);
        
        // Act - Total would be 16 hours (exceeds 12 hour limit)
        $result = $this->service->validateEntry($entry);
        
        // Assert
        $this->assertFalse($result->isValid());
        $this->assertStringContains('maximum daily hours', 
                                   $result->getViolations()[0]->getMessage());
    }
    
    /** @test */
    public function it_handles_edge_case_of_zero_duration(): void
    {
        $entry = new Entry();
        $entry->setStart(new \DateTime('09:00'));
        $entry->setEnd(new \DateTime('09:00')); // Same time
        
        $result = $this->service->validateEntry($entry);
        
        $this->assertFalse($result->isValid());
        $this->assertStringContains('duration must be greater than zero',
                                   $result->getViolations()[0]->getMessage());
    }
}
```

### Repository Testing

```php
<?php
// tests/Unit/Repository/EntryRepositoryTest.php

namespace Tests\Unit\Repository;

use App\Repository\EntryRepository;
use App\Entity\Entry;
use App\Entity\User;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntryRepositoryTest extends KernelTestCase
{
    private EntityManager $em;
    private EntryRepository $repository;
    
    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = $kernel->getContainer()->get('doctrine')->getManager();
        $this->repository = $this->em->getRepository(Entry::class);
    }
    
    /** @test */
    public function it_finds_entries_by_date_range(): void
    {
        // Arrange
        $user = $this->createUser();
        $this->createEntry($user, '2024-01-15', 480);
        $this->createEntry($user, '2024-01-16', 360);
        $this->createEntry($user, '2024-01-20', 420); // Outside range
        $this->em->flush();
        
        // Act
        $entries = $this->repository->findByDateRange(
            $user,
            new \DateTime('2024-01-15'),
            new \DateTime('2024-01-16')
        );
        
        // Assert
        $this->assertCount(2, $entries);
        $this->assertEquals('2024-01-15', $entries[0]->getDay()->format('Y-m-d'));
        $this->assertEquals('2024-01-16', $entries[1]->getDay()->format('Y-m-d'));
    }
    
    /** @test */ 
    public function it_calculates_monthly_totals_correctly(): void
    {
        // Arrange
        $user = $this->createUser();
        $this->createEntry($user, '2024-01-15', 480); // 8 hours
        $this->createEntry($user, '2024-01-16', 360); // 6 hours
        $this->createEntry($user, '2024-02-01', 240); // Different month
        $this->em->flush();
        
        // Act
        $total = $this->repository->getMonthlyTotal($user, 2024, 1);
        
        // Assert
        $this->assertEquals(840, $total); // 14 hours = 840 minutes
    }
    
    private function createUser(): User
    {
        $user = new User();
        $user->setUsername('testuser');
        $user->setEmail('test@example.com');
        $this->em->persist($user);
        return $user;
    }
    
    private function createEntry(User $user, string $date, int $duration): Entry
    {
        $entry = new Entry();
        $entry->setUser($user);
        $entry->setDay(new \DateTime($date));
        $entry->setDuration($duration);
        $entry->setDescription('Test entry');
        $this->em->persist($entry);
        return $entry;
    }
}
```

### Testing with Fixtures

```php
<?php
// tests/Unit/DataFixtures/TestDataBuilder.php

namespace Tests\Unit\DataFixtures;

final class TestDataBuilder
{
    public static function createUser(array $overrides = []): User
    {
        $user = new User();
        $user->setUsername($overrides['username'] ?? 'testuser');
        $user->setEmail($overrides['email'] ?? 'test@example.com');
        $user->setRoles($overrides['roles'] ?? ['ROLE_DEV']);
        
        return $user;
    }
    
    public static function createProject(array $overrides = []): Project
    {
        $project = new Project();
        $project->setName($overrides['name'] ?? 'Test Project');
        $project->setActive($overrides['active'] ?? true);
        
        return $project;
    }
    
    public static function createTimeEntry(array $overrides = []): Entry
    {
        $entry = new Entry();
        $entry->setDay(new \DateTime($overrides['date'] ?? '2024-01-15'));
        $entry->setStart(new \DateTime($overrides['start'] ?? '09:00'));
        $entry->setEnd(new \DateTime($overrides['end'] ?? '17:00'));
        $entry->setDescription($overrides['description'] ?? 'Test work');
        
        return $entry;
    }
}
```

---

## Integration Testing

### Database Integration

```php
<?php
// tests/Integration/Database/EntryPersistenceTest.php

namespace Tests\Integration\Database;

use App\Entity\Entry;
use App\Entity\User;
use App\Entity\Project;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\ORM\EntityManager;

final class EntryPersistenceTest extends KernelTestCase
{
    private EntityManager $em;
    
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        
        // Start transaction for test isolation
        $this->em->beginTransaction();
    }
    
    protected function tearDown(): void
    {
        // Rollback transaction to clean state
        $this->em->rollback();
        parent::tearDown();
    }
    
    /** @test */
    public function it_persists_complete_entry_with_relationships(): void
    {
        // Arrange
        $user = $this->createPersistedUser();
        $project = $this->createPersistedProject();
        
        $entry = new Entry();
        $entry->setUser($user);
        $entry->setProject($project);
        $entry->setDay(new \DateTime('2024-01-15'));
        $entry->setStart(new \DateTime('09:00'));
        $entry->setEnd(new \DateTime('17:00'));
        $entry->setDescription('Integration test entry');
        $entry->setTicket('TEST-123');
        
        // Act
        $this->em->persist($entry);
        $this->em->flush();
        $this->em->clear(); // Clear to force database fetch
        
        // Assert
        $persistedEntry = $this->em->getRepository(Entry::class)->find($entry->getId());
        $this->assertNotNull($persistedEntry);
        $this->assertEquals('Integration test entry', $persistedEntry->getDescription());
        $this->assertEquals(480, $persistedEntry->getDuration()); // Auto-calculated
        $this->assertEquals('TEST-123', $persistedEntry->getTicket());
        
        // Verify relationships
        $this->assertEquals($user->getId(), $persistedEntry->getUser()->getId());
        $this->assertEquals($project->getId(), $persistedEntry->getProject()->getId());
    }
    
    /** @test */
    public function it_enforces_database_constraints(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception\NotNullConstraintViolationException::class);
        
        $entry = new Entry();
        // Missing required fields (user, day)
        $entry->setDescription('Invalid entry');
        
        $this->em->persist($entry);
        $this->em->flush();
    }
    
    /** @test */
    public function it_handles_concurrent_entry_creation(): void
    {
        // Simulate concurrent user creating entries
        $user = $this->createPersistedUser();
        $project = $this->createPersistedProject();
        
        // Create two entries with overlapping times
        $entry1 = $this->createEntry($user, $project, '14:00', '18:00');
        $entry2 = $this->createEntry($user, $project, '16:00', '20:00');
        
        $this->em->persist($entry1);
        $this->em->flush();
        
        // This should trigger validation error at application level
        // (Database allows it, but business logic should prevent)
        $this->em->persist($entry2);
        $this->em->flush();
        
        // Verify both entries exist (integration test verifies persistence)
        $entries = $this->em->getRepository(Entry::class)
                           ->findBy(['user' => $user]);
        $this->assertCount(2, $entries);
    }
    
    private function createPersistedUser(): User
    {
        $user = new User();
        $user->setUsername('integrationuser');
        $user->setEmail('integration@test.com');
        $user->setRoles(['ROLE_DEV']);
        $this->em->persist($user);
        $this->em->flush();
        
        return $user;
    }
    
    private function createPersistedProject(): Project
    {
        $project = new Project();
        $project->setName('Integration Test Project');
        $project->setActive(true);
        $this->em->persist($project);
        $this->em->flush();
        
        return $project;
    }
    
    private function createEntry(User $user, Project $project, 
                               string $start, string $end): Entry
    {
        $entry = new Entry();
        $entry->setUser($user);
        $entry->setProject($project);
        $entry->setDay(new \DateTime('2024-01-15'));
        $entry->setStart(new \DateTime($start));
        $entry->setEnd(new \DateTime($end));
        $entry->setDescription('Concurrent test entry');
        
        return $entry;
    }
}
```

### LDAP Integration Testing

```php
<?php
// tests/Integration/LDAP/AuthenticationTest.php

namespace Tests\Integration\LDAP;

use App\Security\LdapAuthenticator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AuthenticationTest extends WebTestCase
{
    /** @test */
    public function it_authenticates_valid_ldap_user(): void
    {
        // Skip if LDAP server not available
        if (!$this->isLdapAvailable()) {
            $this->markTestSkipped('LDAP server not available');
        }
        
        $client = static::createClient();
        
        // Attempt login with valid LDAP credentials
        $client->request('POST', '/login', [
            '_username' => 'testuser',
            '_password' => 'testpassword'
        ]);
        
        // Should redirect to dashboard on success
        $this->assertResponseRedirects('/dashboard');
        
        // Verify user session
        $session = $client->getRequest()->getSession();
        $this->assertTrue($session->has('_security_main'));
    }
    
    /** @test */
    public function it_creates_user_when_auto_creation_enabled(): void
    {
        if (!$this->isLdapAvailable()) {
            $this->markTestSkipped('LDAP server not available');
        }
        
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        
        // Ensure user doesn't exist
        $userRepository = $em->getRepository(User::class);
        $existingUser = $userRepository->findOneBy(['username' => 'newuser']);
        if ($existingUser) {
            $em->remove($existingUser);
            $em->flush();
        }
        
        $client = static::createClient();
        
        // Login with new LDAP user
        $client->request('POST', '/login', [
            '_username' => 'newuser',
            '_password' => 'newuserpass'
        ]);
        
        $this->assertResponseRedirects('/dashboard');
        
        // Verify user was created
        $createdUser = $userRepository->findOneBy(['username' => 'newuser']);
        $this->assertNotNull($createdUser);
        $this->assertEquals(['ROLE_DEV'], $createdUser->getRoles());
    }
    
    private function isLdapAvailable(): bool
    {
        $ldapHost = $_ENV['LDAP_HOST'] ?? null;
        if (!$ldapHost || $ldapHost === 'mock://ldap.test') {
            return false;
        }
        
        $connection = @ldap_connect($ldapHost);
        return $connection !== false;
    }
}
```

### External API Integration

```php
<?php
// tests/Integration/JIRA/WorklogSyncTest.php

namespace Tests\Integration\JIRA;

use App\Service\Integration\JiraWorklogService;
use App\Entity\Entry;
use App\Entity\TicketSystem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

final class WorklogSyncTest extends KernelTestCase
{
    private JiraWorklogService $service;
    private MockHandler $mockHandler;
    
    protected function setUp(): void
    {
        self::bootKernel();
        
        // Mock HTTP client for controlled testing
        $this->mockHandler = new MockHandler();
        $httpClient = new Client(['handler' => HandlerStack::create($this->mockHandler)]);
        
        $this->service = new JiraWorklogService($httpClient);
    }
    
    /** @test */
    public function it_syncs_time_entry_to_jira(): void
    {
        // Arrange
        $entry = $this->createTimeEntry();
        $ticketSystem = $this->createJiraTicketSystem();
        
        // Mock successful JIRA API response
        $this->mockHandler->append(new Response(201, [], json_encode([
            'id' => 'jira-worklog-123',
            'timeSpentSeconds' => 28800 // 8 hours
        ])));
        
        // Act
        $result = $this->service->syncEntry($entry, $ticketSystem);
        
        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('jira-worklog-123', $result->getExternalId());
        $this->assertNull($result->getErrorMessage());
    }
    
    /** @test */
    public function it_handles_jira_api_errors_gracefully(): void
    {
        // Arrange
        $entry = $this->createTimeEntry();
        $ticketSystem = $this->createJiraTicketSystem();
        
        // Mock JIRA API error response
        $this->mockHandler->append(new Response(404, [], json_encode([
            'errorMessages' => ['Issue does not exist or you do not have permission to see it.']
        ])));
        
        // Act
        $result = $this->service->syncEntry($entry, $ticketSystem);
        
        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertStringContains('Issue does not exist', $result->getErrorMessage());
    }
    
    /** @test */
    public function it_retries_on_temporary_failures(): void
    {
        $entry = $this->createTimeEntry();
        $ticketSystem = $this->createJiraTicketSystem();
        
        // Mock temporary failure followed by success
        $this->mockHandler->append(
            new Response(503, [], 'Service Unavailable'), // First attempt fails
            new Response(201, [], json_encode(['id' => 'retry-success-123'])) // Retry succeeds
        );
        
        $result = $this->service->syncEntry($entry, $ticketSystem);
        
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('retry-success-123', $result->getExternalId());
    }
    
    private function createTimeEntry(): Entry
    {
        $entry = new Entry();
        $entry->setDescription('Test integration work');
        $entry->setDuration(480); // 8 hours
        $entry->setDay(new \DateTime('2024-01-15'));
        $entry->setTicket('TEST-123');
        
        return $entry;
    }
    
    private function createJiraTicketSystem(): TicketSystem
    {
        $system = new TicketSystem();
        $system->setName('Test JIRA');
        $system->setType('jira');
        $system->setBookTime(true);
        $system->setUrl('https://test.atlassian.net');
        
        return $system;
    }
}
```

---

## E2E & Browser Testing

### Controller/API Testing

```php
<?php
// tests/Controller/EntryControllerTest.php

namespace Tests\Controller;

use App\Entity\User;
use App\Entity\Project;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class EntryControllerTest extends WebTestCase
{
    /** @test */
    public function it_creates_time_entry_via_api(): void
    {
        $client = static::createClient();
        
        // Authenticate user
        $user = $this->createAuthenticatedUser($client);
        $project = $this->createProject();
        
        // Create entry via API
        $client->jsonRequest('POST', '/api/entries', [
            'day' => '2024-01-15',
            'start' => '09:00',
            'end' => '17:00',
            'description' => 'API test entry',
            'project' => $project->getId()
        ]);
        
        // Assert response
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('API test entry', $responseData['description']);
        $this->assertEquals(480, $responseData['duration']);
        
        // Verify entry was persisted
        $em = static::getContainer()->get('doctrine')->getManager();
        $entry = $em->getRepository(Entry::class)->find($responseData['id']);
        $this->assertNotNull($entry);
        $this->assertEquals($user->getId(), $entry->getUser()->getId());
    }
    
    /** @test */
    public function it_validates_overlapping_entries(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser($client);
        $project = $this->createProject();
        
        // Create first entry
        $client->jsonRequest('POST', '/api/entries', [
            'day' => '2024-01-15',
            'start' => '09:00',
            'end' => '17:00',
            'description' => 'First entry',
            'project' => $project->getId()
        ]);
        $this->assertResponseIsSuccessful();
        
        // Try to create overlapping entry
        $client->jsonRequest('POST', '/api/entries', [
            'day' => '2024-01-15',
            'start' => '16:00',
            'end' => '20:00',
            'description' => 'Overlapping entry',
            'project' => $project->getId()
        ]);
        
        // Should return validation error
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('overlapping_entry', $responseData['error']);
    }
    
    /** @test */
    public function it_requires_authentication_for_api_access(): void
    {
        $client = static::createClient();
        
        // Attempt API call without authentication
        $client->jsonRequest('GET', '/api/entries');
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
    
    /** @test */
    public function it_enforces_role_based_access(): void
    {
        $client = static::createClient();
        
        // Create user with DEV role (not CTL)
        $user = $this->createAuthenticatedUser($client, ['ROLE_DEV']);
        
        // Try to access controller-only endpoint
        $client->jsonRequest('GET', '/api/reports/team/1');
        
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
    
    /** @test */
    public function it_handles_bulk_entry_creation(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser($client);
        $project = $this->createProject();
        
        $client->jsonRequest('POST', '/api/entries/bulk', [
            'entries' => [
                [
                    'day' => '2024-01-15',
                    'preset' => 'vacation',
                    'duration' => 480,
                    'description' => 'Annual leave'
                ],
                [
                    'day' => '2024-01-16',
                    'preset' => 'vacation', 
                    'duration' => 480,
                    'description' => 'Annual leave'
                ]
            ]
        ]);
        
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(2, $responseData['created_count']);
        $this->assertCount(2, $responseData['entries']);
    }
    
    private function createAuthenticatedUser(
        $client, 
        array $roles = ['ROLE_DEV']
    ): User {
        $em = static::getContainer()->get('doctrine')->getManager();
        
        $user = new User();
        $user->setUsername('testuser');
        $user->setEmail('test@example.com');
        $user->setRoles($roles);
        $em->persist($user);
        $em->flush();
        
        // Login user
        $client->loginUser($user);
        
        return $user;
    }
    
    private function createProject(): Project
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        
        $project = new Project();
        $project->setName('Test Project');
        $project->setActive(true);
        $em->persist($project);
        $em->flush();
        
        return $project;
    }
}
```

### Browser Automation Testing

```php
<?php
// tests/E2E/TimeTrackingWorkflowTest.php

namespace Tests\E2E;

use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\Panther\Client;

final class TimeTrackingWorkflowTest extends PantherTestCase
{
    private Client $client;
    
    protected function setUp(): void
    {
        $this->client = static::createPantherClient([
            'browser' => static::CHROME,
            'options' => [
                '--disable-dev-shm-usage',
                '--no-sandbox',
                '--window-size=1920,1080'
            ]
        ]);
    }
    
    /** @test */
    public function user_can_complete_full_time_tracking_workflow(): void
    {
        // Navigate to login page
        $this->client->request('GET', '/');
        $this->assertPageTitleContains('TimeTracker');
        
        // Login
        $this->client->fillField('_username', 'testuser');
        $this->client->fillField('_password', 'testpass');
        $this->client->clickLink('Login');
        
        // Wait for dashboard to load
        $this->client->waitFor('.dashboard');
        $this->assertSelectorTextContains('h1', 'Time Tracking');
        
        // Create new time entry
        $this->client->clickLink('Add Entry');
        $this->client->waitFor('#entry-form');
        
        // Fill entry form
        $this->client->selectFieldOption('project', '1');
        $this->client->fillField('start', '09:00');
        $this->client->fillField('end', '17:00');
        $this->client->fillField('description', 'E2E test work');
        $this->client->fillField('ticket', 'E2E-123');
        
        // Submit form
        $this->client->clickButton('Save Entry');
        
        // Verify entry appears in list
        $this->client->waitFor('.entry-list');
        $this->assertSelectorTextContains('.entry-list', 'E2E test work');
        $this->assertSelectorTextContains('.entry-list', '8:00h');
        
        // Test entry editing
        $this->client->click('.entry-row:first-child .edit-btn');
        $this->client->waitFor('#entry-edit-form');
        
        // Update description
        $this->client->fillField('description', 'E2E test work (updated)');
        $this->client->clickButton('Update Entry');
        
        // Verify update
        $this->client->waitFor('.entry-list');
        $this->assertSelectorTextContains('.entry-list', 'E2E test work (updated)');
        
        // Test delete functionality
        $this->client->rightClick('.entry-row:first-child');
        $this->client->waitFor('.context-menu');
        $this->client->clickLink('Delete');
        
        // Confirm deletion
        $this->client->waitFor('.confirm-dialog');
        $this->client->clickButton('Confirm Delete');
        
        // Verify entry is removed
        $this->client->waitFor('.entry-list:not(:contains("E2E test work"))');
        $this->assertSelectorNotExists('.entry-list:contains("E2E test work")');
    }
    
    /** @test */
    public function it_shows_validation_errors_in_real_time(): void
    {
        $this->loginUser();
        
        // Navigate to entry form
        $this->client->clickLink('Add Entry');
        $this->client->waitFor('#entry-form');
        
        // Enter invalid time range (end before start)
        $this->client->fillField('start', '17:00');
        $this->client->fillField('end', '09:00');
        
        // Trigger validation by focusing another field
        $this->client->fillField('description', 'Test');
        
        // Check for validation error
        $this->client->waitFor('.validation-error');
        $this->assertSelectorTextContains('.validation-error', 
                                         'End time cannot be before start time');
    }
    
    /** @test */
    public function it_handles_concurrent_editing(): void
    {
        $this->markTestSkipped('Requires multiple browser instances');
        
        // This would test optimistic locking and conflict resolution
        // when multiple users edit the same entry simultaneously
    }
    
    private function loginUser(string $username = 'testuser'): void
    {
        $this->client->request('GET', '/');
        $this->client->fillField('_username', $username);
        $this->client->fillField('_password', 'testpass');
        $this->client->clickButton('Login');
        $this->client->waitFor('.dashboard');
    }
}
```

---

## Performance Testing

### Benchmark Suite

```php
<?php
// tests/Performance/EntryCreationBenchmark.php

namespace Tests\Performance;

use App\Service\EntryService;
use App\Entity\User;
use App\Entity\Project;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntryCreationBenchmark extends KernelTestCase
{
    private EntryService $entryService;
    private User $testUser;
    private Project $testProject;
    
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entryService = static::getContainer()->get(EntryService::class);
        $this->setupTestData();
    }
    
    /**
     * @test
     * @group performance
     */
    public function benchmark_single_entry_creation(): void
    {
        $iterations = 100;
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->entryService->createEntry([
                'user' => $this->testUser,
                'project' => $this->testProject,
                'day' => new \DateTime('2024-01-15'),
                'start' => new \DateTime('09:00'),
                'end' => new \DateTime('17:00'),
                'description' => "Benchmark entry {$i}"
            ]);
        }
        
        $duration = microtime(true) - $startTime;
        $memoryUsed = memory_get_usage() - $startMemory;
        $avgTime = $duration / $iterations * 1000; // ms
        
        // Performance assertions
        $this->assertLessThan(50, $avgTime, 
            "Entry creation should take <50ms, actual: {$avgTime}ms");
        $this->assertLessThan(1048576, $memoryUsed, 
            "Memory usage should be <1MB, actual: " . ($memoryUsed/1024/1024) . "MB");
        
        // Log results for tracking
        echo "\nEntry Creation Benchmark:\n";
        echo "- Iterations: {$iterations}\n";
        echo "- Total time: " . round($duration * 1000) . "ms\n";
        echo "- Average time: " . round($avgTime, 2) . "ms per entry\n";
        echo "- Memory used: " . round($memoryUsed/1024) . "KB\n";
    }
    
    /**
     * @test
     * @group performance
     */
    public function benchmark_bulk_entry_creation(): void
    {
        $batchSize = 100;
        $entries = [];
        
        // Prepare data
        for ($i = 0; $i < $batchSize; $i++) {
            $entries[] = [
                'user' => $this->testUser,
                'project' => $this->testProject,
                'day' => new \DateTime("2024-01-" . (($i % 28) + 1)),
                'start' => new \DateTime('09:00'),
                'end' => new \DateTime('17:00'),
                'description' => "Bulk entry {$i}"
            ];
        }
        
        $startTime = microtime(true);
        $this->entryService->createBulkEntries($entries);
        $duration = microtime(true) - $startTime;
        
        $avgTime = $duration / $batchSize * 1000; // ms per entry
        
        $this->assertLessThan(10, $avgTime, 
            "Bulk creation should be <10ms per entry, actual: {$avgTime}ms");
        
        echo "\nBulk Creation Benchmark:\n";
        echo "- Batch size: {$batchSize}\n"; 
        echo "- Total time: " . round($duration * 1000) . "ms\n";
        echo "- Average per entry: " . round($avgTime, 2) . "ms\n";
    }
    
    /**
     * @test
     * @group performance
     */
    public function benchmark_monthly_report_generation(): void
    {
        // Create test data (1000 entries across month)
        $this->createTestEntries(1000);
        
        $startTime = microtime(true);
        $report = $this->entryService->generateMonthlyReport(
            $this->testUser, 
            2024, 
            1
        );
        $duration = microtime(true) - $startTime;
        
        // Report generation should be fast even with lots of data
        $this->assertLessThan(2000, $duration * 1000, 
            "Monthly report should generate in <2s, actual: " . 
            round($duration * 1000) . "ms");
        
        $this->assertNotEmpty($report->getEntries());
        $this->assertGreaterThan(0, $report->getTotalHours());
        
        echo "\nMonthly Report Benchmark:\n";
        echo "- Entries processed: 1000\n";
        echo "- Generation time: " . round($duration * 1000) . "ms\n";
        echo "- Total hours: " . $report->getTotalHours() . "\n";
    }
    
    private function setupTestData(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        
        $this->testUser = new User();
        $this->testUser->setUsername('perftest');
        $this->testUser->setEmail('perf@test.com');
        $em->persist($this->testUser);
        
        $this->testProject = new Project();
        $this->testProject->setName('Performance Test Project');
        $this->testProject->setActive(true);
        $em->persist($this->testProject);
        
        $em->flush();
    }
    
    private function createTestEntries(int $count): void
    {
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $entries[] = [
                'user' => $this->testUser,
                'project' => $this->testProject,
                'day' => new \DateTime("2024-01-" . (($i % 28) + 1)),
                'start' => new \DateTime('09:00'),
                'end' => new \DateTime('17:00'),
                'description' => "Test entry {$i}"
            ];
        }
        
        $this->entryService->createBulkEntries($entries);
    }
}
```

### Load Testing

```php
<?php
// tests/Performance/LoadTest.php

namespace Tests\Performance;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\Process;

final class LoadTest extends WebTestCase
{
    /**
     * @test
     * @group load
     */
    public function it_handles_concurrent_api_requests(): void
    {
        if (!$this->isLoadTestingEnabled()) {
            $this->markTestSkipped('Load testing not enabled');
        }
        
        $concurrency = 10;
        $requests = 100;
        
        // Use Apache Bench or equivalent
        $process = new Process([
            'ab',
            '-n', (string)$requests,
            '-c', (string)$concurrency,
            '-H', 'Authorization: Bearer ' . $this->getTestToken(),
            '-T', 'application/json',
            'http://localhost:8765/api/entries'
        ]);
        
        $process->run();
        
        $this->assertTrue($process->isSuccessful(), 
            "Load test failed: " . $process->getErrorOutput());
        
        // Parse results
        $output = $process->getOutput();
        preg_match('/Requests per second:\s+([0-9.]+)/', $output, $rpsMatches);
        preg_match('/Time per request:\s+([0-9.]+).*mean/', $output, $timeMatches);
        
        $rps = (float)($rpsMatches[1] ?? 0);
        $avgTime = (float)($timeMatches[1] ?? 0);
        
        // Performance assertions
        $this->assertGreaterThan(50, $rps, 
            "Should handle >50 req/sec, actual: {$rps}");
        $this->assertLessThan(2000, $avgTime, 
            "Average response time should be <2s, actual: {$avgTime}ms");
        
        echo "\nLoad Test Results:\n";
        echo "- Requests per second: {$rps}\n";
        echo "- Average response time: {$avgTime}ms\n";
    }
    
    private function isLoadTestingEnabled(): bool
    {
        return $_ENV['ENABLE_LOAD_TESTS'] === 'true';
    }
    
    private function getTestToken(): string
    {
        // Create test user and get JWT token
        $client = static::createClient();
        $client->request('POST', '/api/auth/login', [], [], [], json_encode([
            'username' => 'loadtest',
            'password' => 'loadtest'
        ]));
        
        $response = json_decode($client->getResponse()->getContent(), true);
        return $response['token'];
    }
}
```

---

## Security Testing

### Authentication Security

```php
<?php
// tests/Security/AuthenticationSecurityTest.php

namespace Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticationSecurityTest extends WebTestCase
{
    /** @test */
    public function it_prevents_brute_force_attacks(): void
    {
        $client = static::createClient();
        
        // Attempt multiple failed logins
        for ($i = 0; $i < 6; $i++) {
            $client->request('POST', '/login', [
                '_username' => 'testuser',
                '_password' => 'wrongpassword'
            ]);
        }
        
        // Next attempt should be rate limited
        $client->request('POST', '/login', [
            '_username' => 'testuser',
            '_password' => 'wrongpassword'
        ]);
        
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }
    
    /** @test */
    public function it_sanitizes_username_input(): void
    {
        $client = static::createClient();
        
        // Attempt LDAP injection
        $client->request('POST', '/login', [
            '_username' => 'admin)(|(password=*))',
            '_password' => 'anypassword'
        ]);
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        
        // Verify no successful authentication occurred
        $this->assertFalse($client->getRequest()->getSession()->has('_security_main'));
    }
    
    /** @test */
    public function it_validates_jwt_token_expiry(): void
    {
        $client = static::createClient();
        
        // Create expired token
        $expiredToken = $this->createExpiredJwtToken();
        
        $client->request('GET', '/api/entries', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $expiredToken
        ]);
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
    
    /** @test */
    public function it_prevents_session_fixation(): void
    {
        $client = static::createClient();
        
        // Get initial session ID
        $client->request('GET', '/login');
        $initialSessionId = $client->getRequest()->getSession()->getId();
        
        // Login successfully
        $client->request('POST', '/login', [
            '_username' => 'testuser',
            '_password' => 'testpass'
        ]);
        
        // Session ID should change after login
        $newSessionId = $client->getRequest()->getSession()->getId();
        $this->assertNotEquals($initialSessionId, $newSessionId, 
            'Session ID should regenerate after login');
    }
    
    private function createExpiredJwtToken(): string
    {
        // Create JWT token with past expiry time
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'none']));
        $payload = base64_encode(json_encode([
            'username' => 'testuser',
            'exp' => time() - 3600 // Expired 1 hour ago
        ]));
        
        return $header . '.' . $payload . '.';
    }
}
```

### Input Validation Security

```php
<?php
// tests/Security/InputValidationTest.php

namespace Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class InputValidationTest extends WebTestCase
{
    /** @test */
    public function it_prevents_sql_injection_in_api(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser($client);
        
        // Attempt SQL injection in description field
        $client->jsonRequest('POST', '/api/entries', [
            'day' => '2024-01-15',
            'start' => '09:00',
            'end' => '17:00',
            'description' => "'; DROP TABLE entries; --",
            'project' => 1
        ]);
        
        // Should process normally (Doctrine ORM prevents SQL injection)
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        
        // Verify database is intact
        $em = static::getContainer()->get('doctrine')->getManager();
        $this->assertTrue($em->getConnection()->createSchemaManager()
                            ->tablesExist(['entries']));
    }
    
    /** @test */
    public function it_prevents_xss_in_output(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser($client);
        
        // Create entry with XSS payload
        $client->jsonRequest('POST', '/api/entries', [
            'day' => '2024-01-15',
            'start' => '09:00',
            'end' => '17:00',
            'description' => '<script>alert("XSS")</script>',
            'project' => 1
        ]);
        
        $this->assertResponseIsSuccessful();
        
        // Verify XSS is escaped in HTML output
        $client->request('GET', '/dashboard');
        $content = $client->getResponse()->getContent();
        
        $this->assertStringNotContains('<script>', $content);
        $this->assertStringContains('&lt;script&gt;', $content);
    }
    
    /** @test */
    public function it_validates_file_upload_security(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser($client);
        
        // Attempt to upload PHP file as avatar
        $uploadedFile = $this->createUploadedFile('<?php phpinfo(); ?>', 'avatar.php');
        
        $client->request('POST', '/api/user/avatar', [], [
            'avatar' => $uploadedFile
        ]);
        
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContains('Invalid file type', $response['message']);
    }
    
    /** @test */
    public function it_enforces_csrf_protection(): void
    {
        $client = static::createClient();
        
        // Try form submission without CSRF token
        $client->request('POST', '/entries/create', [
            'entry' => [
                'day' => '2024-01-15',
                'start' => '09:00',
                'end' => '17:00',
                'description' => 'No CSRF test'
            ]
        ]);
        
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }
    
    private function createAuthenticatedUser($client): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        
        $user = new User();
        $user->setUsername('sectest');
        $user->setEmail('security@test.com');
        $user->setRoles(['ROLE_DEV']);
        $em->persist($user);
        $em->flush();
        
        $client->loginUser($user);
        
        return $user;
    }
    
    private function createUploadedFile(string $content, string $filename): UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, $content);
        
        return new UploadedFile($tempFile, $filename);
    }
}
```

---

## CI/CD Integration

### GitHub Actions Workflow

```yaml
# .github/workflows/comprehensive-testing.yml
name: Comprehensive Testing

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main ]

jobs:
  test:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: rootpass
          MYSQL_DATABASE: unittest
          MYSQL_USER: unittest
          MYSQL_PASSWORD: unittest
        ports:
          - 3307:3306
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3
    
    steps:
      - name: Checkout code
        uses: actions/checkout@v4
        
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: ldap, pdo_mysql, intl, zip, opcache
          coverage: xdebug
          tools: composer:v2
          
      - name: Cache Composer dependencies
        uses: actions/cache@v3
        with:
          path: ~/.composer/cache/files
          key: composer-${{ hashFiles('composer.lock') }}
          
      - name: Install dependencies
        run: |
          composer install --no-interaction --prefer-dist --optimize-autoloader
          npm ci
          npm run build
          
      - name: Setup test database
        env:
          DATABASE_URL: "mysql://unittest:unittest@127.0.0.1:3307/unittest"
        run: |
          php bin/console doctrine:database:create --env=test
          php bin/console doctrine:migrations:migrate --env=test --no-interaction
          
      - name: Run static analysis
        run: |
          composer analyze
          composer analyze:arch
          
      - name: Run code style check
        run: composer cs-check
        
      - name: Run unit tests
        run: composer test:unit
        
      - name: Run integration tests  
        run: composer test:integration
        
      - name: Run controller tests
        run: composer test:controller
        
      - name: Run performance benchmarks
        if: github.event_name == 'push'
        run: composer perf:benchmark
        
      - name: Generate coverage report
        run: composer test:coverage
        
      - name: Upload coverage to Codecov
        uses: codecov/codecov-action@v3
        with:
          file: var/coverage.xml
          
      - name: Run security audit
        run: composer security-check
        
      - name: Archive test results
        if: failure()
        uses: actions/upload-artifact@v3
        with:
          name: test-results
          path: |
            var/log/
            var/coverage/
```

### Parallel Test Execution

```yaml
# Parallel test matrix for faster execution
matrix:
  include:
    - test-suite: unit
      php: '8.4'
      description: "Unit tests"
    - test-suite: integration  
      php: '8.4'
      description: "Integration tests"
    - test-suite: controller
      php: '8.4' 
      description: "API tests"
    - test-suite: performance
      php: '8.4'
      description: "Performance tests"

steps:
  - name: Run ${{ matrix.description }}
    run: composer test:${{ matrix.test-suite }}
```

---

## Troubleshooting Tests

### Common Test Issues

#### 1. Database Connection Errors
```bash
# Problem: Database connection refused
SQLSTATE[HY000] [2002] Connection refused

# Solution: Check database service
docker compose ps db_unittest
docker compose logs db_unittest

# Reset test database
make reset-test-db
```

#### 2. Memory Exhaustion
```bash  
# Problem: Fatal error: Allowed memory size exhausted
# Solution: Increase memory limit
php -d memory_limit=2G ./vendor/bin/phpunit

# Or permanently in phpunit.xml
<ini name="memory_limit" value="2G"/>
```

#### 3. Slow Test Execution
```bash
# Use parallel execution
composer test:parallel

# Run only fast tests during development
./vendor/bin/phpunit --exclude-group=slow,integration

# Profile slow tests
./vendor/bin/phpunit --log-junit=var/junit.xml
```

#### 4. Flaky Tests
```bash
# Run tests multiple times to identify flaky ones
for i in {1..10}; do 
  composer test:unit || echo "Failed on iteration $i"
done

# Use test isolation
./vendor/bin/phpunit --process-isolation
```

#### 5. LDAP Testing Issues
```bash
# Problem: LDAP server not available
# Solution: Mock LDAP in tests
export LDAP_HOST=mock://ldap.test

# Or start test LDAP server
docker compose up ldap-dev
```

### Test Debugging

```php
// Add debugging to tests
protected function setUp(): void
{
    if ($_ENV['TEST_DEBUG'] ?? false) {
        $this->expectOutputString(''); // Capture debug output
    }
    parent::setUp();
}

// Debug specific test
TEST_DEBUG=1 ./vendor/bin/phpunit --filter testSpecificMethod

// Use Xdebug for step debugging
export XDEBUG_MODE=debug
./vendor/bin/phpunit --filter testComplexLogic
```

### Performance Monitoring

```bash
# Monitor test performance over time
composer perf:baseline    # Create baseline
composer perf:report     # Compare against baseline
composer perf:dashboard  # Generate performance dashboard

# Track memory usage
php -d memory_limit=1G -d xdebug.mode=profile ./vendor/bin/phpunit
```

---

## Quality Metrics & Reporting

### Coverage Reports

```bash
# Generate HTML coverage report
composer test:coverage

# Text coverage summary  
composer test:coverage-text

# Coverage by test suite
./vendor/bin/phpunit --testsuite=unit --coverage-text
./vendor/bin/phpunit --testsuite=integration --coverage-text
```

### Test Metrics Dashboard

The project includes automated tracking of:

- **Test execution time trends**
- **Coverage progression over time**  
- **Flaky test identification**
- **Performance regression detection**

Access via: `var/test-dashboard.html`

---

**🎯 Testing Success Criteria:**

- ✅ **80%+ Code Coverage** across all test suites
- ✅ **<2 second** average test suite execution
- ✅ **Zero flaky tests** in CI pipeline
- ✅ **100% critical path coverage** (authentication, time entry, reporting)
- ✅ **Automated performance benchmarks** prevent regressions

---

**Last Updated**: 2025-01-20  
**Test Framework**: PHPUnit 12, Symfony Test Framework  
**Questions**: See [Developer Setup](DEVELOPER_SETUP.md#troubleshooting) or create GitHub issue
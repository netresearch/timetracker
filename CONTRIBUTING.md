# Contributing to TimeTracker

**Welcome to the TimeTracker project! We're excited to have you contribute.**

---

## Table of Contents

1. [Code of Conduct](#code-of-conduct)
2. [Getting Started](#getting-started)
3. [Development Workflow](#development-workflow)
4. [Contribution Guidelines](#contribution-guidelines)
5. [Code Standards](#code-standards)
6. [Testing Requirements](#testing-requirements)
7. [Documentation Standards](#documentation-standards)
8. [Review Process](#review-process)
9. [Community](#community)

---

## Code of Conduct

### Our Pledge

We pledge to make participation in our project a harassment-free experience for everyone, regardless of age, body size, disability, ethnicity, sex characteristics, gender identity and expression, level of experience, education, socio-economic status, nationality, personal appearance, race, religion, or sexual identity and orientation.

### Expected Behavior

- **Be respectful** and inclusive in all interactions
- **Use welcoming language** and be considerate of different perspectives  
- **Focus on constructive feedback** and collaborative problem-solving
- **Accept responsibility** for mistakes and learn from them
- **Show empathy** towards other community members

### Unacceptable Behavior

- Harassment, discriminatory language, or personal attacks
- Trolling, insulting comments, or deliberate intimidation
- Publishing private information without consent
- Any conduct that could reasonably be considered inappropriate

### Enforcement

Instances of abusive behavior may be reported to the project team at [conduct@company.com](mailto:conduct@company.com). All reports will be reviewed confidentially and result in appropriate action.

---

## Getting Started

### Prerequisites

Before contributing, ensure you have:

- **Development Environment**: Follow the [Developer Setup Guide](docs/DEVELOPER_SETUP.md)
- **Understanding of the Codebase**: Read the [Project Architecture](docs/PROJECT_INDEX.md)
- **Familiarity with our Stack**: PHP 8.4, Symfony 7.3, MySQL, Redis
- **Git Knowledge**: Basic understanding of Git workflows

### First Contribution

1. **Start Small**: Look for issues labeled `good-first-issue` or `help-wanted`
2. **Ask Questions**: Don't hesitate to ask in GitHub Discussions or issues
3. **Read Documentation**: Familiarize yourself with our coding standards
4. **Set up Development Environment**: Follow our setup guide completely

---

## Development Workflow

### 1. Issue Creation

Before starting work, create or claim an issue:

```markdown
**Issue Template:**

## Problem Description
Clear description of the bug or feature request

## Expected Behavior
What should happen

## Actual Behavior  
What currently happens (for bugs)

## Steps to Reproduce
1. Step one
2. Step two
3. Step three

## Environment
- OS: [e.g. Ubuntu 22.04]
- PHP: [e.g. 8.4.1]  
- Browser: [e.g. Chrome 120]

## Additional Context
Screenshots, logs, or other relevant information
```

### 2. Branch Strategy

```bash
# Create feature branch from main
git checkout main
git pull origin main
git checkout -b feature/ISSUE-NUMBER-short-description

# Examples:
git checkout -b feature/123-add-bulk-time-entry
git checkout -b fix/456-ldap-connection-timeout
git checkout -b docs/789-api-documentation-update
```

### 3. Commit Standards

Follow [Conventional Commits](https://conventionalcommits.org/):

```bash
# Format: type(scope): description
#
# Types: feat, fix, docs, style, refactor, test, chore, perf
# Scope: controller, service, entity, api, ui, config (optional)

# Examples:
git commit -m "feat(api): add bulk time entry creation endpoint"
git commit -m "fix(auth): resolve LDAP connection timeout issue"
git commit -m "docs: update API documentation with examples"
git commit -m "test(service): add unit tests for EntryService"
git commit -m "refactor(controller): extract validation logic to service"
```

### 4. Development Process

```bash
# 1. Make your changes
# Edit files, add features, fix bugs

# 2. Run quality checks
make check-all              # All quality checks
make test                   # Run test suite
make cs-fix                 # Fix code style

# 3. Commit your changes
git add .
git commit -m "feat(auth): implement JWT refresh token support"

# 4. Keep branch updated
git fetch origin
git rebase origin/main      # Preferred over merge

# 5. Push your branch
git push origin feature/123-add-bulk-time-entry

# 6. Create Pull Request
gh pr create --title "feat: Add bulk time entry creation" --body "Implements bulk creation API endpoint for vacation and recurring entries"
```

---

## Contribution Guidelines

### Types of Contributions

| Type | Description | Examples |
|------|-------------|----------|
| **🐛 Bug Fixes** | Resolve existing issues | Authentication failures, data corruption |
| **✨ Features** | Add new functionality | Bulk operations, new integrations |
| **📚 Documentation** | Improve or add docs | API guides, setup instructions |
| **🧪 Tests** | Add or improve tests | Unit tests, integration tests |
| **🔧 Refactoring** | Code improvements | Performance optimization, cleanup |
| **🎨 UI/UX** | Frontend improvements | Better user experience, accessibility |

### Feature Development Guidelines

#### Small Features (< 1 week)
- **Direct Implementation**: Create PR with implementation
- **Single Responsibility**: One feature per PR
- **Complete Solution**: Include tests and documentation

#### Large Features (> 1 week)  
- **RFC Process**: Create detailed RFC document
- **Discussion Period**: Allow community feedback
- **Phased Implementation**: Break into smaller, reviewable chunks

#### RFC Template
```markdown
# RFC: Feature Name

## Summary
Brief description of the feature

## Motivation
Why is this feature needed? What problem does it solve?

## Detailed Design
### Architecture Changes
### API Changes  
### Database Changes
### Security Considerations

## Implementation Plan
### Phase 1: Core functionality
### Phase 2: Additional features
### Phase 3: Polish and optimization

## Testing Strategy
### Unit Tests
### Integration Tests
### Performance Impact

## Documentation Requirements
### User Documentation
### API Documentation
### Developer Documentation

## Risks and Mitigations
### Technical Risks
### Security Risks
### Performance Risks

## Alternatives Considered
What other approaches were considered and why were they rejected?
```

---

## Code Standards

### PHP Standards

#### PSR Compliance
- **PSR-4**: Autoloading standard
- **PSR-12**: Extended coding style
- **PSR-18**: HTTP client interface
- **PSR-20**: Clock interface

#### Code Style
```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Repository\EntryRepository;
use Psr\Log\LoggerInterface;

/**
 * Service for managing time entries with validation and business logic
 */
final class EntryService
{
    public function __construct(
        private readonly EntryRepository $entryRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create a new time entry with validation
     */
    public function createEntry(array $data): Entry
    {
        // Implementation with proper error handling
        try {
            $entry = new Entry();
            // ... implementation
            
            $this->logger->info('Time entry created', [
                'entry_id' => $entry->getId(),
                'user_id' => $entry->getUser()->getId(),
            ]);
            
            return $entry;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create entry', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            
            throw $e;
        }
    }
}
```

#### Naming Conventions
```php
// Classes: PascalCase
class EntryValidationService {}

// Methods: camelCase  
public function validateEntry() {}

// Variables: camelCase
$entryData = [];

// Constants: UPPER_SNAKE_CASE
private const MAX_DAILY_HOURS = 12;

// Database columns: snake_case
$entry->setUpdatedAt();  // maps to updated_at column
```

#### Type Declarations
```php
// Always use strict types
declare(strict_types=1);

// Use type hints everywhere possible
public function processEntry(Entry $entry, array $options = []): EntryResult
{
    // Implementation
}

// Use nullable types when appropriate
public function findUser(?int $id): ?User
{
    return $id ? $this->userRepository->find($id) : null;
}
```

### Database Standards

#### Entity Design
```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EntryRepository::class)]
#[ORM\Table(name: 'entries')]
#[ORM\Index(columns: ['user_id', 'day'], name: 'idx_entries_user_date')]
class Entry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'date')]
    #[Assert\NotNull]
    #[Assert\LessThanOrEqual('today')]
    private \DateTimeInterface $day;

    #[ORM\Column(type: 'integer')]
    #[Assert\Positive]
    private int $duration;

    // Getters and setters with proper type hints
    public function getId(): ?int
    {
        return $this->id;
    }
    
    public function getUser(): User
    {
        return $this->user;
    }
    
    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }
}
```

#### Repository Patterns
```php
<?php

namespace App\Repository;

use App\Entity\Entry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for Entry entity with optimized queries
 */
class EntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    /**
     * Find entries by user and date range with eager loading
     */
    public function findByUserAndDateRange(
        User $user, 
        \DateTimeInterface $start, 
        \DateTimeInterface $end
    ): array {
        return $this->createQueryBuilder('e')
            ->select('e', 'p', 'c')  // Eager load project and customer
            ->leftJoin('e.project', 'p')
            ->leftJoin('p.customer', 'c')
            ->where('e.user = :user')
            ->andWhere('e.day BETWEEN :start AND :end')
            ->setParameter('user', $user)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('e.day', 'DESC')
            ->addOrderBy('e.start', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get monthly total hours for user (optimized query)
     */
    public function getMonthlyTotal(User $user, int $year, int $month): int
    {
        $result = $this->createQueryBuilder('e')
            ->select('SUM(e.duration) as total')
            ->where('e.user = :user')
            ->andWhere('YEAR(e.day) = :year')
            ->andWhere('MONTH(e.day) = :month')
            ->setParameter('user', $user)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }
}
```

### Frontend Standards

#### JavaScript/TypeScript
```javascript
// Use modern JavaScript features
class TimeEntryManager {
    constructor(apiClient) {
        this.apiClient = apiClient;
        this.entries = new Map();
    }

    async createEntry(entryData) {
        try {
            const entry = await this.apiClient.post('/api/entries', entryData);
            this.entries.set(entry.id, entry);
            this.dispatchEvent('entry:created', { entry });
            return entry;
        } catch (error) {
            this.handleError('Failed to create entry', error);
            throw error;
        }
    }

    handleError(message, error) {
        console.error(message, error);
        // Notify user through UI
    }
}

// Use proper error handling
const entryManager = new TimeEntryManager(apiClient);
```

#### CSS/SCSS Standards
```scss
// Use BEM methodology
.time-entry {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1rem;
    border-radius: 0.5rem;
    background-color: var(--color-surface);

    &__header {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    &__actions {
        display: flex;
        gap: 0.5rem;
    }

    &--highlighted {
        background-color: var(--color-surface-highlighted);
    }
}

// Use CSS custom properties
:root {
    --color-primary: #1e40af;
    --color-secondary: #64748b;
    --color-success: #16a34a;
    --color-error: #dc2626;
    --color-warning: #ca8a04;
}
```

---

## Testing Requirements

### Test Coverage Requirements

| Component | Minimum Coverage | Target Coverage |
|-----------|-----------------|-----------------|
| **Controllers** | 70% | 85% |
| **Services** | 80% | 90% |
| **Repositories** | 60% | 80% |
| **Entities** | 50% | 70% |
| **Overall** | 70% | 80% |

### Unit Testing

```php
<?php

namespace Tests\Unit\Service;

use App\Service\EntryValidationService;
use App\Entity\Entry;
use PHPUnit\Framework\TestCase;

/**
 * Test EntryValidationService with comprehensive scenarios
 */
final class EntryValidationServiceTest extends TestCase
{
    private EntryValidationService $service;
    
    protected function setUp(): void
    {
        $this->service = new EntryValidationService(
            $this->createMock(EntryRepository::class)
        );
    }

    /**
     * @test
     * @dataProvider validEntryProvider
     */
    public function it_validates_correct_entries(array $entryData): void
    {
        $entry = $this->createEntry($entryData);
        
        $result = $this->service->validateEntry($entry);
        
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getViolations());
    }

    /**
     * @test
     * @dataProvider invalidEntryProvider
     */
    public function it_rejects_invalid_entries(
        array $entryData, 
        string $expectedViolation
    ): void {
        $entry = $this->createEntry($entryData);
        
        $result = $this->service->validateEntry($entry);
        
        $this->assertFalse($result->isValid());
        $this->assertStringContains($expectedViolation, 
                                   $result->getViolations()[0]->getMessage());
    }

    public static function validEntryProvider(): array
    {
        return [
            'full_day_entry' => [
                ['start' => '09:00', 'end' => '17:00', 'day' => '2024-01-15']
            ],
            'half_day_entry' => [
                ['start' => '09:00', 'end' => '13:00', 'day' => '2024-01-15']
            ],
        ];
    }

    public static function invalidEntryProvider(): array
    {
        return [
            'end_before_start' => [
                ['start' => '17:00', 'end' => '09:00', 'day' => '2024-01-15'],
                'End time cannot be before start time'
            ],
            'future_date' => [
                ['start' => '09:00', 'end' => '17:00', 'day' => '2024-12-31'],
                'Cannot log time in the future'
            ],
        ];
    }

    private function createEntry(array $data): Entry
    {
        $entry = new Entry();
        $entry->setStart(new \DateTime($data['start']));
        $entry->setEnd(new \DateTime($data['end']));
        $entry->setDay(new \DateTime($data['day']));
        
        return $entry;
    }
}
```

### Integration Testing

```php
<?php

namespace Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration test for time entry API endpoints
 */
final class EntryApiTest extends WebTestCase
{
    /** @test */
    public function it_creates_entry_via_api(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        
        $client->jsonRequest('POST', '/api/entries', [
            'day' => '2024-01-15',
            'start' => '09:00',
            'end' => '17:00',
            'description' => 'Integration test work',
            'project' => 1
        ]);
        
        $this->assertResponseStatusCodeSame(201);
        
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('Integration test work', $response['description']);
        $this->assertEquals(480, $response['duration']);
        
        // Verify persistence
        $em = static::getContainer()->get('doctrine')->getManager();
        $entry = $em->getRepository(Entry::class)->find($response['id']);
        $this->assertNotNull($entry);
    }
    
    /** @test */
    public function it_validates_overlapping_entries(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        
        // Create first entry
        $this->createEntry($user, '09:00', '17:00');
        
        // Try to create overlapping entry
        $client->jsonRequest('POST', '/api/entries', [
            'day' => '2024-01-15',
            'start' => '16:00',
            'end' => '20:00',
            'description' => 'Overlapping work',
            'project' => 1
        ]);
        
        $this->assertResponseStatusCodeSame(422);
        
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('validation_failed', $response['error']);
    }
    
    private function createAuthenticatedUser(): User
    {
        // Implementation for creating and authenticating test user
    }
}
```

### Performance Testing

```php
<?php

namespace Tests\Performance;

use PHPUnit\Framework\TestCase;

/**
 * Performance benchmarks for critical operations
 * 
 * @group performance
 */
final class EntryPerformanceTest extends TestCase
{
    /** @test */
    public function benchmark_entry_creation(): void
    {
        $iterations = 100;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->entryService->createEntry([
                'user' => $this->testUser,
                'day' => new \DateTime('2024-01-15'),
                'duration' => 480,
                'description' => "Performance test {$i}"
            ]);
        }
        
        $duration = microtime(true) - $startTime;
        $avgTime = ($duration / $iterations) * 1000; // ms
        
        $this->assertLessThan(50, $avgTime, 
            "Entry creation should be <50ms, got {$avgTime}ms");
    }
}
```

---

## Documentation Standards

### Code Documentation

```php
<?php

/**
 * Service for managing time entry operations with validation
 * 
 * This service handles creation, update, and validation of time entries
 * including business logic for overlapping detection and daily limits.
 * 
 * @author Development Team
 * @since 4.1.0
 */
final class EntryService
{
    /**
     * Creates a new time entry with comprehensive validation
     * 
     * This method performs several validation checks:
     * - Overlapping entry detection
     * - Daily hour limits
     * - Future date restrictions
     * - Required field validation
     * 
     * @param array $data Entry data containing user, day, duration, description
     * @return Entry The created and persisted entry
     * 
     * @throws ValidationException When entry data fails validation
     * @throws DatabaseException When persistence fails
     * 
     * @example
     * $entry = $service->createEntry([
     *     'user' => $user,
     *     'day' => new \DateTime('2024-01-15'),
     *     'start' => new \DateTime('09:00'),
     *     'end' => new \DateTime('17:00'),
     *     'description' => 'Feature development'
     * ]);
     */
    public function createEntry(array $data): Entry
    {
        // Implementation
    }
}
```

### API Documentation

```yaml
# All endpoints must be documented in OpenAPI format
paths:
  /api/entries:
    post:
      summary: Create a new time entry
      description: |
        Creates a new time entry with validation for overlapping times
        and daily hour limits. Supports both start/end times and duration.
      tags:
        - Entries
      security:
        - bearerAuth: []
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/EntryCreateRequest'
            examples:
              start_end_times:
                summary: Entry with start/end times
                value:
                  day: "2024-01-15"
                  start: "09:00"
                  end: "17:00"
                  description: "Feature development"
                  project: 1
              duration_based:
                summary: Entry with duration
                value:
                  day: "2024-01-15"
                  duration: 480
                  description: "Meeting attendance"
                  project: 1
      responses:
        '201':
          description: Entry created successfully
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Entry'
        '422':
          description: Validation error
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ValidationError'
              example:
                error: "validation_failed"
                message: "Entry validation failed"
                violations:
                  - field: "end"
                    message: "End time cannot be before start time"
```

### README Standards

Each major feature should include:

- **Purpose**: What problem it solves
- **Usage Examples**: Practical code examples
- **Configuration**: Required settings
- **Testing**: How to test the feature
- **Troubleshooting**: Common issues and solutions

---

## Review Process

### Pull Request Template

```markdown
## Description
Brief description of changes and motivation

## Type of Change
- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)  
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation update
- [ ] Performance improvement
- [ ] Code refactoring

## Related Issue
Closes #[issue_number]

## Testing
- [ ] Unit tests pass
- [ ] Integration tests pass
- [ ] Manual testing completed
- [ ] Performance impact assessed

## Code Quality
- [ ] Code follows project standards
- [ ] Self-review completed
- [ ] Documentation updated
- [ ] No breaking changes (or properly documented)

## Screenshots (if applicable)
Add screenshots for UI changes

## Migration Notes (if applicable)
Any special deployment or migration steps required
```

### Review Checklist

#### For Authors
- ✅ **Self-review completed**: Review your own code first
- ✅ **Tests included**: Adequate test coverage for changes
- ✅ **Documentation updated**: Code comments and user docs
- ✅ **Breaking changes documented**: Migration notes included
- ✅ **Performance considered**: No obvious performance regressions
- ✅ **Security reviewed**: No obvious security issues

#### For Reviewers
- ✅ **Code quality**: Follows project standards and best practices
- ✅ **Architecture**: Changes fit well with existing codebase
- ✅ **Testing**: Appropriate tests with good coverage
- ✅ **Documentation**: Clear and complete documentation
- ✅ **Security**: No security vulnerabilities introduced
- ✅ **Performance**: No significant performance impact

### Review Guidelines

#### What to Look For
1. **Correctness**: Does the code work as intended?
2. **Clarity**: Is the code readable and well-documented?
3. **Consistency**: Does it follow project conventions?
4. **Security**: Are there any security implications?
5. **Performance**: Any performance considerations?
6. **Testing**: Adequate test coverage?

#### How to Give Feedback
- **Be constructive**: Suggest improvements, don't just point out problems
- **Be specific**: Reference line numbers and provide examples
- **Explain rationale**: Help the author understand the reasoning
- **Acknowledge good work**: Highlight positive aspects
- **Ask questions**: If something is unclear, ask for clarification

#### Example Review Comments
```markdown
**Good:**
"Consider extracting this validation logic into a separate method for better testability and reusability."

**Better:**
"This validation logic could be extracted into a `validateTimeRange()` method. This would make it easier to test in isolation and reuse in other places. What do you think?"

**Best:**  
"Great work on the error handling! One suggestion: consider extracting lines 45-60 into a `validateTimeRange()` method. This would improve testability and make the logic reusable for the bulk entry feature we're planning. Here's a rough example:

```php
private function validateTimeRange(DateTime $start, DateTime $end): ValidationResult
{
    // validation logic here
}
```
"
```

### Merge Requirements

Before a PR can be merged, it must have:
- ✅ **2+ approvals** from maintainers
- ✅ **All CI checks passing** (tests, code quality, security)
- ✅ **No merge conflicts** with target branch
- ✅ **Documentation updated** if needed
- ✅ **Breaking changes approved** by lead maintainers

---

## Community

### Communication Channels

| Channel | Purpose | Response Time |
|---------|---------|---------------|
| **GitHub Issues** | Bug reports, feature requests | 2-3 business days |
| **GitHub Discussions** | Questions, ideas, general discussion | 1-2 business days |
| **Slack #timetracker-dev** | Real-time development chat | During business hours |
| **Email** | Security issues, private matters | 24 hours |

### Getting Help

1. **Check Documentation**: Start with our comprehensive docs
2. **Search Issues**: Your question might already be answered
3. **GitHub Discussions**: Ask questions in the community
4. **Code Examples**: Look at existing code for patterns
5. **Ask Maintainers**: Reach out if you're stuck

### Recognition

We recognize contributions in several ways:

- **Contributors List**: All contributors listed in README
- **Release Notes**: Significant contributions highlighted
- **Hall of Fame**: Outstanding contributors featured
- **Swag**: Stickers and swag for regular contributors
- **Speaking Opportunities**: Conference speaking opportunities

### Maintainer Responsibilities

Current maintainers commit to:
- **Timely Reviews**: Respond to PRs within 3 business days
- **Community Support**: Answer questions and provide guidance  
- **Code Quality**: Maintain high standards and consistency
- **Documentation**: Keep documentation current and accurate
- **Release Management**: Regular, stable releases

---

## Release Process

### Version Strategy

We follow [Semantic Versioning](https://semver.org/):

- **MAJOR** (4.0.0): Breaking changes
- **MINOR** (4.1.0): New features (backward compatible)  
- **PATCH** (4.1.1): Bug fixes (backward compatible)

### Release Checklist

- ✅ **All tests passing** on main branch
- ✅ **Security audit complete** with no high/critical issues
- ✅ **Performance benchmarks** within acceptable ranges
- ✅ **Documentation updated** including changelog
- ✅ **Migration scripts** tested and validated
- ✅ **Docker images** built and tagged
- ✅ **Release notes** drafted with contributor acknowledgments

---

**🎉 Thank you for contributing to TimeTracker!**

Your contributions help make time tracking better for everyone. Whether you're fixing a bug, adding a feature, improving documentation, or helping other users, every contribution matters.

**Questions?** Don't hesitate to reach out:
- 💬 [GitHub Discussions](https://github.com/netresearch/timetracker/discussions)
- 🐛 [GitHub Issues](https://github.com/netresearch/timetracker/issues)
- 📧 [Email the Team](mailto:timetracker-dev@company.com)

---

**Last Updated**: 2025-01-20  
**Contributing Guidelines Version**: 1.0  
**Next Review**: 2025-04-20
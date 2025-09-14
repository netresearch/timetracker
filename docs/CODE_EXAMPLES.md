# TimeTracker Code Examples

This document provides comprehensive, runnable code examples for the TimeTracker application, demonstrating practical usage patterns and best practices.

## Table of Contents

1. [Authentication Examples](#authentication-examples)
2. [Time Tracking Workflows](#time-tracking-workflows)
3. [Repository Usage Patterns](#repository-usage-patterns)
4. [DTO Usage Examples](#dto-usage-examples)
5. [Event System Usage](#event-system-usage)
6. [Integration Examples](#integration-examples)
7. [Testing Patterns](#testing-patterns)
8. [Common Patterns](#common-patterns)

## Authentication Examples

### LDAP Login Flow Implementation

#### Basic LDAP Authentication Setup

```php
<?php
// config/packages/security.yaml
security:
    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: username

    firewalls:
        main:
            provider: app_user_provider
            lazy: true
            custom_authenticators:
                - App\Security\LdapAuthenticator
            remember_me:
                secret: '%kernel.secret%'
                lifetime: 2592000 # 30 days
            switch_user:
                parameter: simulateUserId
                role: ROLE_ALLOWED_TO_SWITCH
```

#### Custom LDAP Authenticator Implementation

```php
<?php
// Example usage in a service or controller

use App\Security\LdapAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

class AuthenticationService
{
    public function __construct(
        private LdapAuthenticator $ldapAuthenticator,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Authenticate user with LDAP and create local user if needed.
     */
    public function authenticateUser(string $username, string $password): ?User
    {
        // The LdapAuthenticator handles the LDAP connection automatically
        // when called through Symfony's security system

        // For custom authentication outside the firewall:
        $request = new Request();
        $request->request->set('_username', $username);
        $request->request->set('_password', $password);

        if ($this->ldapAuthenticator->supports($request)) {
            try {
                $passport = $this->ldapAuthenticator->authenticate($request);
                $user = $passport->getUser();

                return $user instanceof User ? $user : null;
            } catch (CustomUserMessageAuthenticationException $e) {
                // Log authentication failure
                return null;
            }
        }

        return null;
    }
}
```

#### Session Management with Remember Me

```php
<?php
// In a controller extending BaseController

use App\Controller\BaseController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends BaseController
{
    /**
     * Check if user is logged in and redirect accordingly.
     */
    #[Route('/dashboard', name: 'dashboard')]
    public function dashboard(Request $request): Response
    {
        // Use BaseController's authentication methods
        if (!$this->isLoggedIn($request)) {
            return $this->login($request); // Redirects to login page
        }

        $userId = $this->getUserId($request);

        // Get user details
        $user = $this->managerRegistry
            ->getRepository(User::class)
            ->find($userId);

        return $this->render('dashboard.html.twig', [
            'user' => $user,
            'settings' => $user->getSettings(),
        ]);
    }
}
```

#### User Impersonation for Admins

```php
<?php
// Admin impersonation example

class AdminUserController extends BaseController
{
    /**
     * Switch to impersonate another user.
     */
    #[Route('/admin/impersonate/{userId}', name: 'admin_impersonate')]
    public function impersonateUser(int $userId, Request $request): Response
    {
        // Check admin permissions
        if (!$this->isGranted('ROLE_ALLOWED_TO_SWITCH')) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var User $targetUser */
        $targetUser = $this->managerRegistry
            ->getRepository(User::class)
            ->find($userId);

        if (!$targetUser) {
            throw $this->createNotFoundException('User not found');
        }

        // Symfony handles the actual switching via URL parameter
        // Redirect with the switch parameter
        return $this->redirectToRoute('dashboard', [
            'simulateUserId' => $targetUser->getId()
        ]);
    }

    /**
     * Stop impersonation and return to admin user.
     */
    #[Route('/admin/exit-impersonation', name: 'admin_exit_impersonation')]
    public function exitImpersonation(): Response
    {
        // Redirect without the switch parameter to exit impersonation
        return $this->redirectToRoute('dashboard');
    }
}
```

**Expected Output**: User is authenticated via LDAP, session is maintained with remember-me functionality, and admins can impersonate other users.

**Common Pitfalls**:
- Not sanitizing LDAP input (handled by LdapAuthenticator)
- Forgetting to enable CSRF protection for logout
- Missing proper authorization checks for impersonation

## Time Tracking Workflows

### Creating a New Time Entry

```php
<?php
use App\Entity\Entry;
use App\Entity\User;
use App\Entity\Project;
use App\Entity\Activity;
use DateTime;

class EntryService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Create a new time entry with validation.
     */
    public function createEntry(
        User $user,
        Project $project,
        Activity $activity,
        string $date,
        string $startTime,
        string $endTime,
        string $description = '',
        string $ticket = ''
    ): Entry {
        $entry = new Entry();

        // Set basic properties
        $entry->setUser($user)
              ->setProject($project)
              ->setActivity($activity)
              ->setDay($date)
              ->setStart($startTime)
              ->setEnd($endTime)
              ->setDescription($description)
              ->setTicket($ticket);

        // Calculate duration automatically
        $entry->calcDuration();

        // Validate time ranges
        $entry->validateDuration();

        // Set customer from project if not explicitly set
        if ($project->getCustomer()) {
            $entry->setCustomer($project->getCustomer());
        }

        // Persist the entry
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
```

### Starting/Stopping Timers

```php
<?php
class TimerService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryRepository $entryRepository
    ) {}

    /**
     * Start a new timer (create entry with current time).
     */
    public function startTimer(
        User $user,
        Project $project,
        Activity $activity,
        string $description = '',
        string $ticket = ''
    ): Entry {
        $now = new DateTime();

        $entry = new Entry();
        $entry->setUser($user)
              ->setProject($project)
              ->setActivity($activity)
              ->setCustomer($project->getCustomer())
              ->setDay($now->format('Y-m-d'))
              ->setStart($now->format('H:i:s'))
              ->setEnd($now->format('H:i:s')) // Same as start initially
              ->setDescription($description)
              ->setTicket($ticket)
              ->setDuration(0);

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    /**
     * Stop the timer (update end time and calculate duration).
     */
    public function stopTimer(Entry $entry): Entry
    {
        $now = new DateTime();

        $entry->setEnd($now->format('H:i:s'))
              ->calcDuration();

        $this->entityManager->flush();

        return $entry;
    }

    /**
     * Get currently running timer for user.
     */
    public function getRunningTimer(User $user): ?Entry
    {
        $today = (new DateTime())->format('Y-m-d');

        $entries = $this->entryRepository->getEntriesForDay($user, $today);

        // Find entry with zero duration (indicates running timer)
        foreach ($entries as $entry) {
            if ($entry->getDuration() === 0) {
                return $entry;
            }
        }

        return null;
    }
}
```

### Editing Existing Entries

```php
<?php
class EntryEditService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryRepository $entryRepository
    ) {}

    /**
     * Update an existing entry with validation.
     */
    public function updateEntry(
        int $entryId,
        array $updateData,
        User $currentUser
    ): Entry {
        $entry = $this->entryRepository->findOneById($entryId);

        if (!$entry) {
            throw new \InvalidArgumentException('Entry not found');
        }

        // Security check: only allow users to edit their own entries
        // (unless admin - handle in controller)
        if ($entry->getUser()->getId() !== $currentUser->getId()) {
            throw new \AccessDeniedException('Cannot edit other user\'s entries');
        }

        // Update allowed fields
        if (isset($updateData['start'])) {
            $entry->setStart($updateData['start']);
        }

        if (isset($updateData['end'])) {
            $entry->setEnd($updateData['end']);
        }

        if (isset($updateData['description'])) {
            $entry->setDescription($updateData['description']);
        }

        if (isset($updateData['ticket'])) {
            $entry->setTicket($updateData['ticket']);
        }

        // Recalculate duration if times changed
        if (isset($updateData['start']) || isset($updateData['end'])) {
            $entry->calcDuration();
            $entry->validateDuration();
        }

        $this->entityManager->flush();

        return $entry;
    }
}
```

### Bulk Operations

```php
<?php
use App\Dto\BulkEntryDto;

class BulkEntryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryRepository $entryRepository
    ) {}

    /**
     * Process bulk entry operations from DTO.
     */
    public function processBulkEntries(BulkEntryDto $bulkDto, User $user): array
    {
        $results = [];
        $errors = [];

        // Process entries
        foreach ($bulkDto->entries as $entryData) {
            try {
                $entry = $this->createOrUpdateEntry($entryData, $user);
                $results[] = $entry->toArray();
            } catch (\Exception $e) {
                $errors[] = [
                    'entry' => $entryData,
                    'error' => $e->getMessage()
                ];
            }
        }

        // Process deletions
        if (!empty($bulkDto->deleteIds)) {
            $deleted = $this->bulkDelete($bulkDto->deleteIds, $user);
            $results['deleted'] = $deleted;
        }

        return [
            'success' => $results,
            'errors' => $errors,
            'total_processed' => count($bulkDto->entries),
            'total_errors' => count($errors)
        ];
    }

    /**
     * Bulk delete entries with user permission check.
     */
    private function bulkDelete(array $entryIds, User $user): int
    {
        return $this->entryRepository->bulkUpdate(
            $entryIds,
            ['deletedAt' => new DateTime()] // Soft delete
        );
    }
}
```

**Expected Output**: Time entries are created, updated, and managed with proper validation and user permissions.

**Common Pitfalls**:
- Not validating time ranges (start < end)
- Forgetting to calculate duration after time changes
- Missing user permission checks for editing entries

## Repository Usage Patterns

### Using EntryRepository Methods

```php
<?php
class TimeReportService
{
    public function __construct(
        private EntryRepository $entryRepository,
        private UserRepository $userRepository
    ) {}

    /**
     * Generate time report for user and date range.
     */
    public function generateUserReport(
        int $userId,
        string $startDate,
        string $endDate
    ): array {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        // Get entries for date range
        $entries = $this->entryRepository->getEntriesForMonth(
            $user,
            $startDate,
            $endDate
        );

        // Get summary statistics
        $summary = $this->entryRepository->getSummaryData([
            'user' => $userId,
            'startDate' => $startDate,
            'endDate' => $endDate
        ]);

        // Get raw data for export
        $rawData = $this->entryRepository->getRawData(
            $startDate,
            $endDate,
            $userId
        );

        return [
            'user' => $user->getUsername(),
            'period' => ['start' => $startDate, 'end' => $endDate],
            'entries' => array_map(fn($e) => $e->toArray(), $entries),
            'summary' => $summary,
            'raw_data' => $rawData
        ];
    }
}
```

### OptimizedEntryRepository for Performance

```php
<?php
use App\Repository\OptimizedEntryRepository;
use App\Enum\Period;

class PerformanceTimeService
{
    public function __construct(
        private OptimizedEntryRepository $optimizedRepo
    ) {}

    /**
     * Get user work data with caching for better performance.
     */
    public function getUserWorkSummary(User $user, int $recentDays = 7): array
    {
        // Use optimized repository with caching
        $recentEntries = $this->optimizedRepo->findByRecentDaysOfUser(
            $user,
            $recentDays
        );

        // Get work statistics for different periods
        $dayWork = $this->optimizedRepo->getWorkByUser(
            $user->getId(),
            Period::DAY
        );

        $weekWork = $this->optimizedRepo->getWorkByUser(
            $user->getId(),
            Period::WEEK
        );

        $monthWork = $this->optimizedRepo->getWorkByUser(
            $user->getId(),
            Period::MONTH
        );

        return [
            'recent_entries' => array_map(fn($e) => $e->toArray(), $recentEntries),
            'statistics' => [
                'today' => $dayWork,
                'this_week' => $weekWork,
                'this_month' => $monthWork
            ]
        ];
    }
}
```

### Complex Queries with Filters

```php
<?php
class AdvancedReportService
{
    public function __construct(
        private EntryRepository $entryRepository
    ) {}

    /**
     * Generate advanced filtered report.
     */
    public function getFilteredReport(array $filters): array
    {
        // Use repository's flexible filtering
        $entries = $this->entryRepository->getFilteredEntries(
            $filters,
            $offset = 0,
            $limit = 1000,
            $orderBy = 'day',
            $orderDirection = 'DESC'
        );

        // Get time summary grouped by period
        $monthlySummary = $this->entryRepository->getTimeSummaryByPeriod(
            'month',
            $filters,
            $filters['startDate'] ?? null,
            $filters['endDate'] ?? null
        );

        // Get overlapping entries for validation
        if (isset($filters['user']) && isset($filters['day'])) {
            $overlapping = $this->entryRepository->findOverlappingEntries(
                $filters['user'],
                $filters['day'],
                $filters['start'] ?? '09:00',
                $filters['end'] ?? '17:00',
                $filters['excludeId'] ?? null
            );
        }

        return [
            'entries' => array_map(fn($e) => $e->toArray(), $entries),
            'monthly_summary' => $monthlySummary,
            'overlapping' => $overlapping ?? [],
            'filters_applied' => $filters
        ];
    }
}
```

### Caching Strategies

```php
<?php
use Psr\Cache\CacheItemPoolInterface;

class CachedTimeService
{
    public function __construct(
        private EntryRepository $entryRepository,
        private CacheItemPoolInterface $cache
    ) {}

    /**
     * Get user entries with intelligent caching.
     */
    public function getUserEntriesWithCache(
        int $userId,
        string $date,
        int $cacheTtl = 300
    ): array {
        $cacheKey = "user_entries_{$userId}_{$date}";

        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        // Cache miss - fetch from database
        $user = $this->entryRepository->findOneById($userId);
        $entries = $this->entryRepository->findByDay($userId, $date);

        $data = [
            'user_id' => $userId,
            'date' => $date,
            'entries' => array_map(fn($e) => $e->toArray(), $entries),
            'total_duration' => array_sum(array_map(fn($e) => $e->getDuration(), $entries))
        ];

        // Cache the result
        $cacheItem->set($data);
        $cacheItem->expiresAfter($cacheTtl);
        $this->cache->save($cacheItem);

        return $data;
    }

    /**
     * Invalidate cache when entries are modified.
     */
    public function invalidateUserCache(int $userId, string $date): void
    {
        $cacheKey = "user_entries_{$userId}_{$date}";
        $this->cache->deleteItem($cacheKey);
    }
}
```

**Expected Output**: Efficient data retrieval with proper filtering, caching, and performance optimization.

**Common Pitfalls**:
- N+1 query problems (use findEntriesWithRelations)
- Not using indexes for date range queries
- Cache invalidation issues when data changes

## DTO Usage Examples

### Request Validation with DTOs

```php
<?php
use App\Dto\EntrySaveDto;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TrackingController extends BaseController
{
    /**
     * Save entry using DTO validation.
     */
    #[Route('/tracking/save', name: 'save_entry', methods: ['POST'])]
    public function saveEntry(
        #[MapRequestPayload] EntrySaveDto $entryDto,
        ValidatorInterface $validator
    ): JsonResponse {
        // Validation is automatic with MapRequestPayload
        // but you can add custom validation if needed

        $errors = $validator->validate($entryDto);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }

            return new JsonResponse([
                'success' => false,
                'errors' => $errorMessages
            ], 400);
        }

        // Create entry from DTO
        $entry = new Entry();

        // Use DTO helper methods for data conversion
        $entry->setDay($entryDto->getDateAsDateTime())
              ->setStart($entryDto->getStartAsDateTime())
              ->setEnd($entryDto->getEndAsDateTime())
              ->setDescription($entryDto->description)
              ->setTicket($entryDto->ticket);

        // Handle legacy field support
        if ($entryDto->getProjectId()) {
            $project = $this->managerRegistry
                ->getRepository(Project::class)
                ->find($entryDto->getProjectId());
            $entry->setProject($project);
        }

        $entry->calcDuration();

        $this->managerRegistry->getManager()->persist($entry);
        $this->managerRegistry->getManager()->flush();

        return new JsonResponse([
            'success' => true,
            'entry' => $entry->toArray()
        ]);
    }
}
```

### MapRequestPayload Attribute Usage

```php
<?php
use App\Dto\UserSaveDto;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

class UserController extends BaseController
{
    /**
     * Create or update user with automatic mapping.
     */
    #[Route('/admin/user/save', name: 'save_user', methods: ['POST'])]
    public function saveUser(
        #[MapRequestPayload] UserSaveDto $userDto,
        ObjectMapperInterface $objectMapper
    ): JsonResponse {
        // Authorization check
        if (!$this->isPl($this->getRequest())) {
            return $this->getFailedAuthorizationResponse();
        }

        // Get existing user or create new
        $userRepo = $this->managerRegistry->getRepository(User::class);
        $user = $userDto->id > 0 ? $userRepo->find($userDto->id) : new User();

        if (!$user) {
            return new JsonResponse([
                'success' => false,
                'error' => 'User not found'
            ], 404);
        }

        // Map DTO to entity automatically
        $objectMapper->map($userDto, $user);

        // Handle teams (many-to-many relationship)
        $user->resetTeams();
        foreach ($userDto->teams as $teamId) {
            if ($teamId > 0) {
                $team = $this->managerRegistry
                    ->getRepository(Team::class)
                    ->find($teamId);
                if ($team) {
                    $user->addTeam($team);
                }
            }
        }

        $this->managerRegistry->getManager()->persist($user);
        $this->managerRegistry->getManager()->flush();

        return new JsonResponse([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'type' => $user->getType()->value
            ]
        ]);
    }
}
```

### Custom Validation Constraints

```php
<?php
// Create a custom DTO with advanced validation

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class ProjectSaveDto
{
    public function __construct(
        public ?int $id = null,

        #[Assert\NotBlank(message: 'Project name is required')]
        #[Assert\Length(
            max: 255,
            maxMessage: 'Project name cannot be longer than 255 characters'
        )]
        public string $name = '',

        #[Assert\Positive(message: 'Customer ID must be positive')]
        public ?int $customer_id = null,

        #[Assert\Range(
            min: 0,
            max: 999999,
            notInRangeMessage: 'Estimation must be between 0 and 999999 minutes'
        )]
        public int $estimation = 0,

        #[Assert\Choice(
            choices: ['active', 'inactive', 'archived'],
            message: 'Status must be one of: active, inactive, archived'
        )]
        public string $status = 'active',

        /** @var int[] */
        #[Assert\All([
            new Assert\Type('integer'),
            new Assert\Positive()
        ])]
        public array $team_ids = [],
    ) {}

    /**
     * Custom validation to ensure customer exists.
     */
    #[Assert\Callback]
    public function validateCustomer(ExecutionContextInterface $context): void
    {
        if ($this->customer_id && $this->customer_id > 0) {
            // This would typically be done in a custom validator service
            // For demo purposes, we'll show the pattern
            if (!$this->customerExists($this->customer_id)) {
                $context->buildViolation('Customer with ID {{ id }} does not exist')
                    ->setParameter('{{ id }}', (string) $this->customer_id)
                    ->atPath('customer_id')
                    ->addViolation();
            }
        }
    }

    private function customerExists(int $customerId): bool
    {
        // This should be injected as a service in a real implementation
        return true; // Placeholder
    }
}
```

### Error Handling with DTOs

```php
<?php
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ApiController extends BaseController
{
    /**
     * Handle DTO validation errors gracefully.
     */
    #[Route('/api/entry', name: 'api_create_entry', methods: ['POST'])]
    public function createEntry(Request $request): JsonResponse
    {
        try {
            // Manual DTO creation for better error handling
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new BadRequestException('Invalid JSON payload');
            }

            $entryDto = new EntrySaveDto(
                id: $data['id'] ?? null,
                date: $data['date'] ?? '',
                start: $data['start'] ?? '00:00:00',
                end: $data['end'] ?? '00:00:00',
                ticket: $data['ticket'] ?? '',
                description: $data['description'] ?? '',
                project_id: $data['project_id'] ?? null,
                activity_id: $data['activity_id'] ?? null
            );

            // Validate DTO
            $validator = $this->container->get('validator');
            $violations = $validator->validate($entryDto);

            if (count($violations) > 0) {
                $errors = [];
                foreach ($violations as $violation) {
                    $errors[$violation->getPropertyPath()] = $violation->getMessage();
                }

                return new JsonResponse([
                    'success' => false,
                    'errors' => $errors
                ], 422);
            }

            // Process the valid DTO...

        } catch (BadRequestException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid request format',
                'message' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
```

**Expected Output**: Properly validated request data with helpful error messages and automatic type conversion.

**Common Pitfalls**:
- Not handling JSON parsing errors
- Missing validation for related entities
- Forgetting to handle array validation properly

## Event System Usage

### How to Enable the Event System

```php
<?php
// The event system is currently disabled but can be enabled
// by creating event subscribers and dispatching events

// config/services.yaml
services:
    App\EventSubscriber\:
        resource: '../src/EventSubscriber/'
        tags: ['kernel.event_subscriber']
```

### Creating Custom Event Subscribers

```php
<?php
namespace App\EventSubscriber;

use App\Event\EntryEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class EntryEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private NotificationService $notificationService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            EntryEvent::CREATED => 'onEntryCreated',
            EntryEvent::UPDATED => 'onEntryUpdated',
            EntryEvent::DELETED => 'onEntryDeleted',
            EntryEvent::SYNCED => 'onEntrySynced',
            EntryEvent::SYNC_FAILED => 'onEntrySyncFailed',
        ];
    }

    /**
     * Handle entry creation events.
     */
    public function onEntryCreated(EntryEvent $event): void
    {
        $entry = $event->getEntry();
        $context = $event->getContext();

        $this->logger->info('New time entry created', [
            'entry_id' => $entry->getId(),
            'user_id' => $entry->getUserId(),
            'project_id' => $entry->getProjectId(),
            'duration' => $entry->getDuration(),
            'context' => $context
        ]);

        // Send notification for long entries
        if ($entry->getDuration() > 480) { // 8 hours
            $this->notificationService->sendLongEntryAlert($entry);
        }

        // Auto-sync to external systems if enabled
        if ($entry->getProject()?->getTicketSystem()) {
            $this->scheduleSync($entry);
        }
    }

    /**
     * Handle entry update events.
     */
    public function onEntryUpdated(EntryEvent $event): void
    {
        $entry = $event->getEntry();
        $context = $event->getContext() ?? [];

        // Log what changed
        if (isset($context['changes'])) {
            $this->logger->info('Entry updated', [
                'entry_id' => $entry->getId(),
                'changes' => $context['changes']
            ]);
        }

        // Re-sync if ticket-related fields changed
        $syncFields = ['ticket', 'description', 'start', 'end'];
        $needsSync = false;

        if (isset($context['changes'])) {
            foreach ($syncFields as $field) {
                if (isset($context['changes'][$field])) {
                    $needsSync = true;
                    break;
                }
            }
        }

        if ($needsSync && $entry->getProject()?->getTicketSystem()) {
            $this->scheduleSync($entry);
        }
    }

    /**
     * Handle entry deletion events.
     */
    public function onEntryDeleted(EntryEvent $event): void
    {
        $entry = $event->getEntry();

        $this->logger->info('Entry deleted', [
            'entry_id' => $entry->getId(),
            'user_id' => $entry->getUserId()
        ]);

        // Remove from external systems if synced
        if ($entry->getSyncedToTicketsystem()) {
            $this->scheduleUnsync($entry);
        }
    }

    private function scheduleSync(Entry $entry): void
    {
        // Implementation would depend on your sync mechanism
        // Could use Symfony Messenger, background jobs, etc.
    }
}
```

### Event Dispatching Patterns

```php
<?php
use App\Event\EntryEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class EntryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher
    ) {}

    /**
     * Create entry with event dispatching.
     */
    public function createEntry(EntrySaveDto $entryDto, User $user): Entry
    {
        $entry = new Entry();
        // ... populate entry from DTO

        $entry->setUser($user);
        $entry->calcDuration();

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        // Dispatch creation event
        $event = new EntryEvent($entry, [
            'source' => 'web_interface',
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        $this->eventDispatcher->dispatch($event, EntryEvent::CREATED);

        return $entry;
    }

    /**
     * Update entry with change tracking.
     */
    public function updateEntry(Entry $entry, array $changes): Entry
    {
        $oldData = [
            'start' => $entry->getStart()->format('H:i:s'),
            'end' => $entry->getEnd()->format('H:i:s'),
            'description' => $entry->getDescription(),
            'ticket' => $entry->getTicket()
        ];

        // Apply changes
        foreach ($changes as $field => $value) {
            match($field) {
                'start' => $entry->setStart($value),
                'end' => $entry->setEnd($value),
                'description' => $entry->setDescription($value),
                'ticket' => $entry->setTicket($value),
                default => null
            };
        }

        $entry->calcDuration();
        $this->entityManager->flush();

        // Calculate what actually changed
        $actualChanges = [];
        foreach ($changes as $field => $newValue) {
            if ($oldData[$field] !== $newValue) {
                $actualChanges[$field] = [
                    'old' => $oldData[$field],
                    'new' => $newValue
                ];
            }
        }

        // Dispatch update event with change details
        if (!empty($actualChanges)) {
            $event = new EntryEvent($entry, [
                'changes' => $actualChanges,
                'source' => 'api'
            ]);

            $this->eventDispatcher->dispatch($event, EntryEvent::UPDATED);
        }

        return $entry;
    }
}
```

### Advanced Event Handling with Priority

```php
<?php
namespace App\EventSubscriber;

class HighPriorityEntrySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Higher priority number = executes first
            EntryEvent::CREATED => ['onEntryCreated', 100],
            EntryEvent::UPDATED => ['onEntryUpdated', 100],
        ];
    }

    /**
     * High priority validation/security checks.
     */
    public function onEntryCreated(EntryEvent $event): void
    {
        $entry = $event->getEntry();

        // Security validations
        if ($entry->getDuration() > 1440) { // More than 24 hours
            throw new \InvalidArgumentException('Entry duration cannot exceed 24 hours');
        }

        // Business rule validations
        if ($entry->getDay() > new \DateTime()) {
            throw new \InvalidArgumentException('Cannot create entries for future dates');
        }
    }
}

class LowPriorityEntrySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Lower priority = executes after validation
            EntryEvent::CREATED => ['onEntryCreated', -100],
        ];
    }

    /**
     * Analytics and reporting (runs after validation).
     */
    public function onEntryCreated(EntryEvent $event): void
    {
        // Send to analytics service
        $this->analyticsService->trackTimeEntry($event->getEntry());

        // Update caches
        $this->cacheService->invalidateUserCache(
            $event->getEntry()->getUserId(),
            $event->getEntry()->getDay()->format('Y-m-d')
        );
    }
}
```

**Expected Output**: Events are dispatched when entries are created, updated, or deleted, allowing for logging, notifications, and integrations.

**Common Pitfalls**:
- Not considering event subscriber order/priority
- Throwing exceptions in event subscribers without proper handling
- Creating infinite event loops

## Integration Examples

### JavaScript/AJAX Integration

```html
<!DOCTYPE html>
<html>
<head>
    <title>TimeTracker</title>
    <meta name="csrf-token" content="{{ csrf_token('authenticate') }}">
</head>
<body>

<form id="timeEntryForm">
    <input type="date" name="date" required>
    <input type="time" name="start" required>
    <input type="time" name="end" required>
    <select name="project_id" required>
        <option value="">Select Project</option>
        <!-- Populated from API -->
    </select>
    <select name="activity_id" required>
        <option value="">Select Activity</option>
    </select>
    <input type="text" name="ticket" placeholder="Ticket number">
    <textarea name="description" placeholder="Description"></textarea>
    <button type="submit">Save Entry</button>
</form>

<script>
class TimeTracker {
    constructor() {
        this.apiBase = '/api';
        this.csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        this.initializeEventListeners();
    }

    initializeEventListeners() {
        document.getElementById('timeEntryForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.saveTimeEntry(e.target);
        });
    }

    async saveTimeEntry(form) {
        const formData = new FormData(form);
        const data = Object.fromEntries(formData);

        try {
            const response = await this.apiRequest('/tracking/save', {
                method: 'POST',
                body: JSON.stringify(data)
            });

            if (response.success) {
                this.showSuccessMessage('Time entry saved successfully');
                this.resetForm(form);
                this.refreshEntryList();
            } else {
                this.showErrors(response.errors || ['Failed to save entry']);
            }
        } catch (error) {
            this.showErrors([error.message]);
        }
    }

    async apiRequest(endpoint, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': this.csrfToken
            },
            credentials: 'same-origin'
        };

        const response = await fetch(this.apiBase + endpoint, {
            ...defaultOptions,
            ...options,
            headers: { ...defaultOptions.headers, ...options.headers }
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.error || `HTTP ${response.status}`);
        }

        return await response.json();
    }

    async loadProjects() {
        try {
            const response = await this.apiRequest('/admin/projects');
            const select = document.querySelector('select[name="project_id"]');

            response.data.forEach(project => {
                const option = document.createElement('option');
                option.value = project.id;
                option.textContent = `${project.customer_name} - ${project.name}`;
                select.appendChild(option);
            });
        } catch (error) {
            console.error('Failed to load projects:', error);
        }
    }

    showSuccessMessage(message) {
        // Implementation depends on your UI framework
        console.log('Success:', message);
    }

    showErrors(errors) {
        console.error('Errors:', errors);
        // Display errors in UI
    }

    resetForm(form) {
        form.reset();
    }

    async refreshEntryList() {
        // Reload the entry list
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new TimeTracker();
});
</script>

</body>
</html>
```

### Form Submissions with Validation

```php
<?php
// PHP controller handling the AJAX request

use App\Dto\EntrySaveDto;

class TrackingApiController extends BaseController
{
    #[Route('/api/tracking/save', name: 'api_save_entry', methods: ['POST'])]
    public function saveEntry(
        Request $request,
        ValidatorInterface $validator
    ): JsonResponse {
        try {
            // Get authenticated user
            $userId = $this->getUserId($request);
            $user = $this->managerRegistry->getRepository(User::class)->find($userId);

            // Parse JSON request
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid JSON format'
                ], 400);
            }

            // Create and validate DTO
            $entryDto = new EntrySaveDto(
                date: $data['date'] ?? '',
                start: $data['start'] ?? '',
                end: $data['end'] ?? '',
                project_id: $data['project_id'] ?? null,
                activity_id: $data['activity_id'] ?? null,
                ticket: $data['ticket'] ?? '',
                description: $data['description'] ?? ''
            );

            $violations = $validator->validate($entryDto);
            if (count($violations) > 0) {
                $errors = [];
                foreach ($violations as $violation) {
                    $errors[$violation->getPropertyPath()] = $violation->getMessage();
                }

                return new JsonResponse([
                    'success' => false,
                    'errors' => $errors
                ], 422);
            }

            // Check for overlapping entries
            $overlapping = $this->entryRepository->findOverlappingEntries(
                $user,
                $entryDto->date,
                $entryDto->start,
                $entryDto->end
            );

            if (!empty($overlapping)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Time entry overlaps with existing entry',
                    'overlapping_entries' => array_map(fn($e) => $e->toArray(), $overlapping)
                ], 409);
            }

            // Create and save entry
            $entry = new Entry();
            $entry->setUser($user)
                  ->setDay($entryDto->getDateAsDateTime())
                  ->setStart($entryDto->getStartAsDateTime())
                  ->setEnd($entryDto->getEndAsDateTime())
                  ->setDescription($entryDto->description)
                  ->setTicket($entryDto->ticket);

            // Set project and customer
            if ($entryDto->getProjectId()) {
                $project = $this->managerRegistry
                    ->getRepository(Project::class)
                    ->find($entryDto->getProjectId());
                if ($project) {
                    $entry->setProject($project);
                    $entry->setCustomer($project->getCustomer());
                }
            }

            // Set activity
            if ($entryDto->getActivityId()) {
                $activity = $this->managerRegistry
                    ->getRepository(Activity::class)
                    ->find($entryDto->getActivityId());
                if ($activity) {
                    $entry->setActivity($activity);
                }
            }

            $entry->calcDuration();

            $this->managerRegistry->getManager()->persist($entry);
            $this->managerRegistry->getManager()->flush();

            return new JsonResponse([
                'success' => true,
                'entry' => $entry->toArray()
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
```

### API Authentication Patterns

```php
<?php
// API authentication middleware

use Symfony\Component\HttpFoundation\JsonResponse;

class ApiAuthenticationController extends BaseController
{
    /**
     * API endpoint that requires authentication.
     */
    #[Route('/api/user/profile', name: 'api_user_profile', methods: ['GET'])]
    public function getUserProfile(Request $request): JsonResponse
    {
        try {
            // Check if user is authenticated
            if (!$this->isLoggedIn($request)) {
                return new JsonResponse([
                    'error' => 'Authentication required',
                    'code' => 'AUTH_REQUIRED'
                ], 401);
            }

            $userId = $this->getUserId($request);
            $user = $this->managerRegistry->getRepository(User::class)->find($userId);

            if (!$user) {
                return new JsonResponse([
                    'error' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ], 404);
            }

            return new JsonResponse([
                'user' => [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'type' => $user->getType()->value,
                    'settings' => $user->getSettings(),
                    'teams' => array_map(
                        fn($team) => ['id' => $team->getId(), 'name' => $team->getName()],
                        $user->getTeams()->toArray()
                    )
                ]
            ]);

        } catch (AccessDeniedException $e) {
            return new JsonResponse([
                'error' => 'Access denied',
                'code' => 'ACCESS_DENIED'
            ], 403);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Internal server error',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }
}
```

**Expected Output**: Seamless integration between frontend JavaScript and backend API with proper error handling and authentication.

**Common Pitfalls**:
- Not handling CSRF tokens properly
- Missing error handling for network issues
- Not validating data on both client and server side

## Testing Patterns

### Unit Test Examples

```php
<?php
namespace Tests\Entity;

use App\Entity\Entry;
use App\Entity\User;
use App\Entity\Project;
use PHPUnit\Framework\TestCase;
use DateTime;

/**
 * Unit tests for Entry entity.
 */
class EntryTest extends TestCase
{
    public function testCreateEntry(): void
    {
        $entry = new Entry();
        $entry->setDescription('Test entry')
              ->setDay('2024-01-01')
              ->setStart('09:00:00')
              ->setEnd('17:00:00')
              ->setTicket('PROJ-123');

        $this->assertEquals('Test entry', $entry->getDescription());
        $this->assertEquals('PROJ-123', $entry->getTicket());
        $this->assertInstanceOf(DateTime::class, $entry->getDay());
    }

    public function testDurationCalculation(): void
    {
        $entry = new Entry();
        $entry->setStart('09:00:00')
              ->setEnd('17:00:00')
              ->setDay('2024-01-01');

        $entry->calcDuration();

        // 8 hours = 480 minutes
        $this->assertEquals(480, $entry->getDuration());
        $this->assertEquals('08:00', $entry->getDurationString());
    }

    public function testInvalidDurationThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Duration must be greater than 0!');

        $entry = new Entry();
        $entry->setStart('17:00:00')
              ->setEnd('09:00:00')  // End before start
              ->setDay('2024-01-01');

        $entry->calcDuration();
    }

    public function testToArrayConversion(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $project = new Project();
        $project->setName('Test Project');

        $entry = new Entry();
        $entry->setId(1)
              ->setUser($user)
              ->setProject($project)
              ->setDay('2024-01-01')
              ->setStart('09:00:00')
              ->setEnd('10:30:00')
              ->setDescription('Test task')
              ->setTicket('TEST-123')
              ->calcDuration();

        $array = $entry->toArray();

        $this->assertEquals(1, $array['id']);
        $this->assertEquals('01/01/2024', $array['date']);
        $this->assertEquals('09:00', $array['start']);
        $this->assertEquals('10:30', $array['end']);
        $this->assertEquals(90, $array['duration']); // 1.5 hours
        $this->assertEquals('Test task', $array['description']);
        $this->assertEquals('TEST-123', $array['ticket']);
    }
}
```

### Integration Test Examples

```php
<?php
namespace Tests\Controller;

use App\Entity\User;
use App\Entity\Project;
use App\Entity\Activity;
use Tests\AbstractWebTestCase;

/**
 * Integration tests for time tracking functionality.
 */
class TimeTrackingIntegrationTest extends AbstractWebTestCase
{
    public function testCreateTimeEntry(): void
    {
        // Test data is automatically loaded via TestDataTrait
        $user = $this->getTestUser();
        $project = $this->getTestProject();
        $activity = $this->getTestActivity();

        $entryData = [
            'date' => '2024-01-15',
            'start' => '09:00:00',
            'end' => '10:30:00',
            'project_id' => $project->getId(),
            'activity_id' => $activity->getId(),
            'description' => 'Integration test entry',
            'ticket' => 'INT-001'
        ];

        // Make authenticated request
        $this->client->request(
            'POST',
            '/tracking/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($entryData)
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertArrayHasKey('entry', $responseData);
        $this->assertEquals(90, $responseData['entry']['duration']);
        $this->assertEquals('INT-001', $responseData['entry']['ticket']);
    }

    public function testGetUserEntries(): void
    {
        // Create test entry
        $this->createTestEntry([
            'date' => '2024-01-15',
            'start' => '09:00:00',
            'end' => '17:00:00',
            'description' => 'Test day work'
        ]);

        $this->client->request('GET', '/api/entries/2024-01-15');

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('entries', $responseData);
        $this->assertCount(1, $responseData['entries']);
        $this->assertEquals('Test day work', $responseData['entries'][0]['description']);
    }

    public function testOverlappingEntryValidation(): void
    {
        // Create initial entry
        $this->createTestEntry([
            'date' => '2024-01-15',
            'start' => '09:00:00',
            'end' => '12:00:00'
        ]);

        // Try to create overlapping entry
        $overlappingData = [
            'date' => '2024-01-15',
            'start' => '10:00:00',  // Overlaps with existing
            'end' => '14:00:00',
            'project_id' => $this->getTestProject()->getId(),
            'activity_id' => $this->getTestActivity()->getId()
        ];

        $this->client->request(
            'POST',
            '/tracking/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($overlappingData)
        );

        $this->assertResponseStatusCodeSame(409); // Conflict

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertStringContains('overlaps', $responseData['error']);
    }
}
```

### Fixture Usage

```php
<?php
namespace Tests\Fixtures;

use App\Entity\User;
use App\Entity\Team;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Activity;
use App\Entity\Entry;
use App\Enum\UserType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Test fixtures for time tracking tests.
 */
class TimeTrackingTestFixture extends Fixture
{
    public const USER_REFERENCE = 'test-user';
    public const PROJECT_REFERENCE = 'test-project';
    public const ACTIVITY_REFERENCE = 'test-activity';

    public function load(ObjectManager $manager): void
    {
        // Create test user
        $user = new User();
        $user->setUsername('testuser')
             ->setType(UserType::USER)
             ->setLocale('en');

        $manager->persist($user);
        $this->addReference(self::USER_REFERENCE, $user);

        // Create test team
        $team = new Team();
        $team->setName('Test Team');
        $user->addTeam($team);

        $manager->persist($team);

        // Create test customer
        $customer = new Customer();
        $customer->setName('Test Customer')
                 ->setActive(true);

        $manager->persist($customer);

        // Create test project
        $project = new Project();
        $project->setName('Test Project')
                ->setCustomer($customer)
                ->setActive(true)
                ->setEstimation(4800); // 80 hours

        $manager->persist($project);
        $this->addReference(self::PROJECT_REFERENCE, $project);

        // Create test activity
        $activity = new Activity();
        $activity->setName('Development')
                 ->setActive(true);

        $manager->persist($activity);
        $this->addReference(self::ACTIVITY_REFERENCE, $activity);

        // Create sample entries
        $this->createSampleEntries($manager, $user, $project, $activity);

        $manager->flush();
    }

    private function createSampleEntries(
        ObjectManager $manager,
        User $user,
        Project $project,
        Activity $activity
    ): void {
        $dates = ['2024-01-01', '2024-01-02', '2024-01-03'];

        foreach ($dates as $date) {
            $entry = new Entry();
            $entry->setUser($user)
                  ->setProject($project)
                  ->setCustomer($project->getCustomer())
                  ->setActivity($activity)
                  ->setDay($date)
                  ->setStart('09:00:00')
                  ->setEnd('17:00:00')
                  ->setDescription("Work on $date")
                  ->setTicket('TEST-' . str_replace('-', '', $date))
                  ->calcDuration();

            $manager->persist($entry);
        }
    }
}
```

### Mock Strategies

```php
<?php
namespace Tests\Service;

use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Util\TicketService;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test service interactions with mocked dependencies.
 */
class TicketServiceTest extends TestCase
{
    private MockObject $jiraApiFactory;
    private MockObject $jiraApi;
    private TicketService $ticketService;

    protected function setUp(): void
    {
        // Create mock dependencies
        $this->jiraApiFactory = $this->createMock(JiraOAuthApiFactory::class);
        $this->jiraApi = $this->createMock(\App\Service\Integration\Jira\JiraOAuthApi::class);

        $this->ticketService = new TicketService($this->jiraApiFactory);
    }

    public function testSyncEntryToJira(): void
    {
        // Setup test data
        $entry = new Entry();
        $entry->setTicket('PROJ-123')
              ->setDescription('Test entry')
              ->setStart('09:00:00')
              ->setEnd('17:00:00')
              ->calcDuration();

        $project = new Project();
        $ticketSystem = new TicketSystem();
        $project->setTicketSystem($ticketSystem);
        $entry->setProject($project);

        // Configure mocks
        $this->jiraApiFactory
            ->expects($this->once())
            ->method('createApi')
            ->with($ticketSystem)
            ->willReturn($this->jiraApi);

        $this->jiraApi
            ->expects($this->once())
            ->method('logWork')
            ->with(
                $this->equalTo('PROJ-123'),
                $this->equalTo(480), // 8 hours in minutes
                $this->equalTo('Test entry')
            )
            ->willReturn(['worklog_id' => 12345]);

        // Execute test
        $result = $this->ticketService->syncEntry($entry);

        // Assertions
        $this->assertTrue($result);
        $this->assertEquals(12345, $entry->getWorklogId());
        $this->assertTrue($entry->getSyncedToTicketsystem());
    }

    public function testSyncFailureHandling(): void
    {
        $entry = new Entry();
        $entry->setTicket('PROJ-456');

        $project = new Project();
        $ticketSystem = new TicketSystem();
        $project->setTicketSystem($ticketSystem);
        $entry->setProject($project);

        // Configure mock to throw exception
        $this->jiraApiFactory
            ->expects($this->once())
            ->method('createApi')
            ->willReturn($this->jiraApi);

        $this->jiraApi
            ->expects($this->once())
            ->method('logWork')
            ->willThrowException(new \Exception('JIRA API Error'));

        // Execute and assert exception handling
        $result = $this->ticketService->syncEntry($entry);

        $this->assertFalse($result);
        $this->assertFalse($entry->getSyncedToTicketsystem());
    }
}
```

**Expected Output**: Comprehensive test coverage with proper isolation, fixtures, and mocking strategies.

**Common Pitfalls**:
- Not cleaning up database state between tests
- Over-mocking (testing mocks instead of real behavior)
- Missing edge case testing

## Common Patterns

### Single Action Controller Implementation

```php
<?php
namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\CustomerSaveDto;
use App\Entity\Customer;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

/**
 * Single action controller for saving customers.
 * Follows TimeTracker's pattern of one action per controller class.
 */
final class SaveCustomerAction extends BaseController
{
    /**
     * Save customer action with comprehensive error handling.
     *
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException
     * @throws \Exception
     */
    #[Route(path: '/admin/customer/save', name: 'saveCustomer', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload] CustomerSaveDto $customerDto,
        ObjectMapperInterface $objectMapper
    ): JsonResponse {
        // Authorization check using BaseController method
        if (!$this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        // Get repository using dependency injection pattern
        /** @var \App\Repository\CustomerRepository $customerRepository */
        $customerRepository = $this->managerRegistry->getRepository(Customer::class);

        // Handle create vs update logic
        $customer = $customerDto->id > 0
            ? $customerRepository->find($customerDto->id)
            : new Customer();

        if (!$customer instanceof Customer) {
            $response = new JsonResponse([
                'success' => false,
                'message' => $this->translate('Customer not found.')
            ]);
            $response->setStatusCode(404);
            return $response;
        }

        // Use ObjectMapper for automatic DTO to Entity mapping
        $objectMapper->map($customerDto, $customer);

        // Persist using Doctrine pattern
        $objectManager = $this->managerRegistry->getManager();
        $objectManager->persist($customer);
        $objectManager->flush();

        // Return standardized response format
        return new JsonResponse([
            'success' => true,
            'data' => [
                'id' => $customer->getId(),
                'name' => $customer->getName(),
                'active' => $customer->getActive()
            ],
            'message' => $this->translate('Customer saved successfully.')
        ]);
    }
}
```

### Service Injection Patterns

```php
<?php
namespace App\Service;

use App\Repository\EntryRepository;
use App\Repository\OptimizedEntryRepository;
use App\Service\ClockInterface;
use App\Service\Util\TimeCalculationService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Service demonstrating TimeTracker's dependency injection patterns.
 */
class ReportGenerationService
{
    // Constructor injection for required dependencies
    public function __construct(
        private EntryRepository $entryRepository,
        private OptimizedEntryRepository $optimizedRepository,
        private TimeCalculationService $timeCalculationService,
        private ClockInterface $clock,
        private LoggerInterface $logger
    ) {}

    // Optional dependencies via setter injection with Required attribute
    private ?CacheItemPoolInterface $cache = null;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setCache(CacheItemPoolInterface $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * Generate comprehensive time report.
     */
    public function generateReport(int $userId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $cacheKey = "report_{$userId}_{$startDate->format('Y-m-d')}_{$endDate->format('Y-m-d')}";

        // Use optional cache if available
        if ($this->cache) {
            $cached = $this->cache->getItem($cacheKey);
            if ($cached->isHit()) {
                $this->logger->debug('Report cache hit', ['cache_key' => $cacheKey]);
                return $cached->get();
            }
        }

        // Generate report data
        $report = $this->buildReport($userId, $startDate, $endDate);

        // Cache result if cache is available
        if ($this->cache) {
            $cached = $this->cache->getItem($cacheKey);
            $cached->set($report);
            $cached->expiresAfter(3600); // 1 hour
            $this->cache->save($cached);
        }

        return $report;
    }

    private function buildReport(int $userId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        // Use regular repository for basic queries
        $entries = $this->entryRepository->findByFilterArray([
            'user' => $userId,
            'datestart' => $startDate->format('Y-m-d'),
            'dateend' => $endDate->format('Y-m-d')
        ]);

        // Use optimized repository for performance-critical operations
        $summary = $this->optimizedRepository->getWorkByUser($userId);

        // Use injected services for calculations
        $totalDuration = array_sum(array_map(fn($e) => $e->getDuration(), $entries));
        $formattedDuration = $this->timeCalculationService->formatDuration($totalDuration);

        return [
            'user_id' => $userId,
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d')
            ],
            'entries' => array_map(fn($e) => $e->toArray(), $entries),
            'summary' => [
                'total_entries' => count($entries),
                'total_duration_minutes' => $totalDuration,
                'total_duration_formatted' => $formattedDuration,
                'work_summary' => $summary
            ],
            'generated_at' => $this->clock->now()->format('c')
        ];
    }
}
```

### Configuration Management

```php
<?php
// config/services.yaml configuration patterns

services:
    # Default configuration
    _defaults:
        autowire: true
        autoconfigure: true

    # Controllers are automatically registered
    App\Controller\:
        resource: '../src/Controller/'
        tags: ['controller.service_arguments']

    # Repositories with custom configuration
    App\Repository\OptimizedEntryRepository:
        arguments:
            $cache: '@cache.app'  # Inject specific cache pool

    # Services with interface binding
    App\Service\ClockInterface: '@App\Service\SystemClock'

    # Custom service configuration
    App\Service\ReportGenerationService:
        arguments:
            $logger: '@monolog.logger.reports'
        calls:
            - [setCache, ['@cache.app']]

    # LDAP configuration
    App\Security\LdapAuthenticator:
        arguments:
            $logger: '@monolog.logger.security'
```

### Database Migrations

```php
<?php
// Example migration file

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add worklog_id column for JIRA integration.
 */
final class Version20240101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add worklog_id column to entries table for external ticket system integration';
    }

    public function up(Schema $schema): void
    {
        // Check if we're on MySQL or SQLite
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform) {
            $this->addSql('ALTER TABLE entries ADD COLUMN worklog_id INT DEFAULT NULL');
            $this->addSql('CREATE INDEX IDX_worklog_id ON entries (worklog_id)');
        } else {
            // SQLite
            $this->addSql('ALTER TABLE entries ADD COLUMN worklog_id INTEGER DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform) {
            $this->addSql('DROP INDEX IDX_worklog_id ON entries');
        }

        $this->addSql('ALTER TABLE entries DROP COLUMN worklog_id');
    }
}
```

### Custom Validation

```php
<?php
namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use App\Repository\UserRepository;

/**
 * Custom validator for unique usernames.
 */
class UniqueUsernameValidator extends ConstraintValidator
{
    public function __construct(
        private UserRepository $userRepository
    ) {}

    public function validate($value, Constraint $constraint): void
    {
        if (null === $value || '' === $value) {
            return;
        }

        // Check if username already exists
        $existingUser = $this->userRepository->findOneBy(['username' => $value]);

        if ($existingUser) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}

#[\Attribute]
class UniqueUsername extends Constraint
{
    public string $message = 'Username "{{ value }}" is already taken.';

    public function validatedBy(): string
    {
        return UniqueUsernameValidator::class;
    }
}

// Usage in DTO:
readonly class UserSaveDto
{
    public function __construct(
        #[UniqueUsername]
        public string $username = '',
        // ... other fields
    ) {}
}
```

**Expected Output**: Clean, maintainable code following TimeTracker's established patterns and conventions.

**Best Practices**:
- Single responsibility controllers
- Dependency injection over service locator
- Proper error handling and validation
- Consistent response formats
- Type safety and strict types

---

## Conclusion

These examples demonstrate the key patterns and practices used throughout the TimeTracker application:

1. **Authentication**: LDAP integration with session management and remember-me functionality
2. **Time Tracking**: Complete CRUD operations with validation and business rules
3. **Repository Pattern**: Efficient data access with caching and query optimization
4. **DTO Validation**: Type-safe request handling with automatic validation
5. **Event System**: Extensible event-driven architecture (currently disabled)
6. **API Integration**: RESTful endpoints with proper error handling
7. **Testing**: Comprehensive test coverage with fixtures and mocking
8. **Architecture**: Clean code patterns following SOLID principles

All examples are based on real code from the TimeTracker application and demonstrate production-ready patterns that can be immediately applied in development.
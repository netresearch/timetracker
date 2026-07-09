<?php

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\TicketSystemType;
use App\Enum\WriteOutcome;
use App\Event\EntryEvent;
use App\EventSubscriber\EntryEventSubscriber;
use App\Exception\Integration\Jira\JiraApiException;
use App\Repository\TicketSystemRepository;
use App\Service\Cache\QueryCacheService;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Sync\WorklogWriteService;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(EntryEventSubscriber::class)]
#[AllowMockObjectsWithoutExpectations]
final class EntryEventSubscriberTest extends TestCase
{
    private JiraOAuthApiFactory&MockObject $jiraOAuthApiFactory;
    private JiraOAuthApiService&MockObject $jiraOAuthApiService;
    private ManagerRegistry&MockObject $managerRegistry;
    private ObjectManager&MockObject $objectManager;
    private QueryCacheService&MockObject $queryCacheService;
    private WorklogWriteService&MockObject $worklogWriteService;
    private LoggerInterface&MockObject $logger;
    private EntryEventSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->jiraOAuthApiFactory = $this->createMock(JiraOAuthApiFactory::class);
        $this->jiraOAuthApiService = $this->createMock(JiraOAuthApiService::class);
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
        $this->objectManager = $this->createMock(ObjectManager::class);
        $this->managerRegistry->method('getManager')->willReturn($this->objectManager);
        $this->queryCacheService = $this->createMock(QueryCacheService::class);
        $this->worklogWriteService = $this->createMock(WorklogWriteService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new EntryEventSubscriber(
            $this->jiraOAuthApiFactory,
            $this->managerRegistry,
            $this->queryCacheService,
            $this->worklogWriteService,
            $this->logger,
        );
    }

    /**
     * Creates an entry stub whose user/project/ticket-system graph satisfies
     * the subscriber's auto-sync conditions (unless overridden).
     *
     * @return array{Entry&Stub, User&Stub, TicketSystem&Stub}
     */
    private function createSyncableEntry(
        bool $bookTime = true,
        TicketSystemType $type = TicketSystemType::JIRA,
        string $ticket = 'ABC-123',
        bool $synced = false,
        ?int $worklogId = null,
    ): array {
        $ticketSystem = self::createStub(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn($bookTime);
        $ticketSystem->method('getType')->willReturn($type);

        $project = self::createStub(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(1);

        $entry = self::createStub(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getTicket')->willReturn($ticket);
        $entry->method('getSyncedToTicketsystem')->willReturn($synced);
        $entry->method('getWorklogId')->willReturn($worklogId);

        return [$entry, $user, $ticketSystem];
    }

    private function expectJiraApiCreatedFor(User&Stub $user, TicketSystem&Stub $ticketSystem): void
    {
        $this->jiraOAuthApiFactory->expects(self::once())
            ->method('create')
            ->with($user, $ticketSystem)
            ->willReturn($this->jiraOAuthApiService);
    }

    private function expectNoJiraApi(): void
    {
        $this->jiraOAuthApiFactory->expects(self::never())
            ->method('create');
        $this->worklogWriteService->expects(self::never())
            ->method('push');
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = EntryEventSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(EntryEvent::CREATED, $events);
        self::assertArrayHasKey(EntryEvent::UPDATED, $events);
        self::assertArrayHasKey(EntryEvent::DELETED, $events);
        self::assertArrayHasKey(EntryEvent::SYNCED, $events);
        self::assertArrayHasKey(EntryEvent::SYNC_FAILED, $events);

        self::assertSame('onEntryCreated', $events[EntryEvent::CREATED]);
        self::assertSame('onEntryUpdated', $events[EntryEvent::UPDATED]);
        self::assertSame('onEntryDeleted', $events[EntryEvent::DELETED]);
        self::assertSame('onEntrySynced', $events[EntryEvent::SYNCED]);
        self::assertSame('onEntrySyncFailed', $events[EntryEvent::SYNC_FAILED]);
    }

    public function testOnEntryCreatedInvalidatesCacheForUser(): void
    {
        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(42);

        $entry = self::createStub(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn(null);

        $this->queryCacheService->expects(self::once())
            ->method('invalidateEntity')
            ->with(Entry::class, 42);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotInvalidateCacheWhenNoUser(): void
    {
        $entry = self::createStub(Entry::class);
        $entry->method('getUser')->willReturn(null);
        $entry->method('getProject')->willReturn(null);

        $this->queryCacheService->expects(self::never())
            ->method('invalidateEntity');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedAutoSyncsToJiraWhenConditionsMet(): void
    {
        [$entry, $user, $ticketSystem] = $this->createSyncableEntry();

        $this->expectJiraApiCreatedFor($user, $ticketSystem);

        $this->worklogWriteService->expects(self::once())
            ->method('push')
            ->with($this->jiraOAuthApiService, $entry, self::anything())
            ->willReturn(WriteOutcome::WRITTEN);

        $this->objectManager->expects(self::once())
            ->method('flush');

        $this->logger->expects(self::exactly(2))
            ->method('info');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenNoProject(): void
    {
        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(1);

        $entry = self::createStub(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn(null);

        $this->expectNoJiraApi();

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenNoTicketSystem(): void
    {
        $project = self::createStub(Project::class);
        $project->method('getTicketSystem')->willReturn(null);

        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(1);

        $entry = self::createStub(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);

        $this->expectNoJiraApi();

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenBookTimeDisabled(): void
    {
        [$entry] = $this->createSyncableEntry(bookTime: false);

        $this->expectNoJiraApi();

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenNotJiraTicketSystem(): void
    {
        [$entry] = $this->createSyncableEntry(type: TicketSystemType::OTRS);

        $this->expectNoJiraApi();

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenEmptyTicket(): void
    {
        [$entry] = $this->createSyncableEntry(ticket: '');

        $this->expectNoJiraApi();

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenZeroTicket(): void
    {
        [$entry] = $this->createSyncableEntry(ticket: '0');

        $this->expectNoJiraApi();

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedLogsErrorOnJiraSyncFailure(): void
    {
        [$entry] = $this->createSyncableEntry();

        $exception = new Exception('Jira API error');
        $this->jiraOAuthApiFactory->method('create')
            ->willReturn($this->jiraOAuthApiService);
        $this->worklogWriteService->expects(self::once())
            ->method('push')
            ->willThrowException($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Auto-sync to JIRA failed', ['exception' => $exception]);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryUpdatedInvalidatesCache(): void
    {
        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(42);

        $entry = self::createStub(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getSyncedToTicketsystem')->willReturn(false);

        $this->queryCacheService->expects(self::once())
            ->method('invalidateEntity')
            ->with(Entry::class, 42);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);
    }

    public function testOnEntryUpdatedSyncsWhenAutoSyncConditionsMet(): void
    {
        [$entry, $user, $ticketSystem] = $this->createSyncableEntry(synced: true, worklogId: 12345);

        $this->expectJiraApiCreatedFor($user, $ticketSystem);

        $this->worklogWriteService->expects(self::once())
            ->method('push')
            ->with($this->jiraOAuthApiService, $entry, self::anything())
            ->willReturn(WriteOutcome::WRITTEN);

        $this->objectManager->expects(self::once())
            ->method('flush');

        $this->logger->expects(self::exactly(2))
            ->method('info');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);
    }

    public function testOnEntryUpdatedSyncsWhenNotPreviouslySynced(): void
    {
        // Catch-up behavior: entries that were never synced (e.g. saved while
        // sync was unavailable) are synced on their next update.
        [$entry, $user, $ticketSystem] = $this->createSyncableEntry();

        $this->expectJiraApiCreatedFor($user, $ticketSystem);

        $this->worklogWriteService->expects(self::once())
            ->method('push')
            ->with($this->jiraOAuthApiService, $entry, self::anything())
            ->willReturn(WriteOutcome::WRITTEN);

        $this->objectManager->expects(self::once())
            ->method('flush');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);
    }

    public function testOnEntryUpdatedDoesNotSyncWhenNoProject(): void
    {
        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(1);

        $entry = self::createStub(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn(null);

        $this->expectNoJiraApi();

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);
    }

    public function testOnEntryUpdatedLogsErrorOnJiraFailure(): void
    {
        [$entry] = $this->createSyncableEntry();

        $exception = new Exception('Jira API error');
        $this->jiraOAuthApiFactory->method('create')
            ->willReturn($this->jiraOAuthApiService);
        $this->worklogWriteService->expects(self::once())
            ->method('push')
            ->willThrowException($exception);

        // Error, not warning: prod's fingers_crossed(action_level: error) handler
        // would swallow a warning, hiding the failed sync entirely.
        $this->logger->expects(self::once())
            ->method('error')
            ->with('JIRA worklog update failed', ['exception' => $exception]);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);
    }

    public function testOnEntryDeletedInvalidatesCache(): void
    {
        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(42);

        $entry = self::createStub(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getSyncedToTicketsystem')->willReturn(false);

        $this->queryCacheService->expects(self::once())
            ->method('invalidateEntity')
            ->with(Entry::class, 42);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryDeleted($event);
    }

    public function testOnEntryDeletedDeletesWorklogFromJira(): void
    {
        [$entry, $user, $ticketSystem] = $this->createSyncableEntry(synced: true, worklogId: 12345);

        $this->expectJiraApiCreatedFor($user, $ticketSystem);

        $this->worklogWriteService->expects(self::once())
            ->method('delete')
            ->with($this->jiraOAuthApiService, $entry);

        $this->objectManager->expects(self::once())
            ->method('flush');

        $this->logger->expects(self::exactly(2))
            ->method('info');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryDeleted($event);
    }

    public function testOnEntryDeletedDoesNotDeleteWhenNotSynced(): void
    {
        [$entry] = $this->createSyncableEntry();

        $this->expectNoJiraApi();

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryDeleted($event);
    }

    public function testOnEntryDeletedDoesNotDeleteWhenNoWorklogId(): void
    {
        [$entry] = $this->createSyncableEntry(synced: true);

        $this->expectNoJiraApi();

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryDeleted($event);
    }

    public function testOnEntryDeletedLogsErrorOnFailure(): void
    {
        [$entry] = $this->createSyncableEntry(synced: true, worklogId: 12345);

        $exception = new Exception('Jira API error');
        $this->jiraOAuthApiFactory->method('create')
            ->willReturn($this->jiraOAuthApiService);
        $this->worklogWriteService->expects(self::once())
            ->method('delete')
            ->willThrowException($exception);

        // Error, not warning: a warning is swallowed by prod's fingers_crossed
        // handler, leaving an orphaned Jira worklog with no trace.
        $this->logger->expects(self::once())
            ->method('error')
            ->with('JIRA worklog deletion failed', ['exception' => $exception]);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryDeleted($event);
    }

    public function testOnEntryDeletedFallsBackToOwnSystemWhenInternalSystemMissing(): void
    {
        // Regression for the orphaned-worklog bug: a project IS configured for an
        // internal Jira mirror, but its internal ticket-system row no longer exists
        // (findInternalTicketSystem() -> null). The worklog was booked on the OWN
        // system, so delete must still clean it up there — the old either/or logic
        // targeted only the (missing) internal system and gave up.
        $ownSystem = self::createStub(TicketSystem::class);
        $ownSystem->method('getBookTime')->willReturn(true);
        $ownSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = self::createStub(Project::class);
        $project->method('hasInternalJiraProjectKey')->willReturn(true);
        $project->method('getInternalJiraTicketSystem')->willReturn('99');
        $project->method('getTicketSystem')->willReturn($ownSystem);

        // The configured internal ticket system id points at a deleted row.
        $repository = $this->createMock(TicketSystemRepository::class);
        $repository->method('find')->willReturn(null);
        $this->managerRegistry->method('getRepository')->willReturn($repository);

        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(1);

        $entry = self::createStub(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getSyncedToTicketsystem')->willReturn(true);
        $entry->method('getWorklogId')->willReturn(12345);

        $this->jiraOAuthApiFactory->expects(self::once())
            ->method('create')
            ->with($user, $ownSystem)
            ->willReturn($this->jiraOAuthApiService);
        $this->worklogWriteService->expects(self::once())
            ->method('delete')
            ->with($this->jiraOAuthApiService, $entry);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryDeleted($event);
    }

    public function testOnEntryDeletedKeepsTryingOtherSystemsAfterAFailure(): void
    {
        // A failure deleting on the first candidate system must not abort cleanup on
        // the others, and an unresolved deletion must surface as an error (not a
        // silent success). Two bookable systems; the delete throws on each.
        $internalSystem = self::createStub(TicketSystem::class);
        $internalSystem->method('getBookTime')->willReturn(true);
        $internalSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $ownSystem = self::createStub(TicketSystem::class);
        $ownSystem->method('getBookTime')->willReturn(true);
        $ownSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = self::createStub(Project::class);
        $project->method('hasInternalJiraProjectKey')->willReturn(true);
        $project->method('getInternalJiraTicketSystem')->willReturn('99');
        $project->method('getTicketSystem')->willReturn($ownSystem);

        $repository = $this->createMock(TicketSystemRepository::class);
        $repository->method('find')->willReturn($internalSystem);
        $this->managerRegistry->method('getRepository')->willReturn($repository);

        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(1);

        $entry = self::createStub(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getSyncedToTicketsystem')->willReturn(true);
        $entry->method('getWorklogId')->willReturn(12345);

        // Both candidate systems are attempted (create once per system) even though
        // the first delete throws.
        $this->jiraOAuthApiFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturn($this->jiraOAuthApiService);
        $this->worklogWriteService->method('delete')
            ->willThrowException(new JiraApiException('boom', 500));

        // The unresolved failure surfaces at error level.
        $this->logger->expects(self::once())
            ->method('error')
            ->with('JIRA worklog deletion failed', self::anything());

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryDeleted($event);
    }

    public function testOnEntrySyncedInvalidatesJiraSyncCacheTag(): void
    {
        $entry = self::createStub(Entry::class);

        $this->queryCacheService->expects(self::once())
            ->method('invalidateTag')
            ->with('jira_sync');

        $this->logger->expects(self::once())
            ->method('info')
            ->with('Entry synced to JIRA');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntrySynced($event);
    }

    public function testOnEntrySyncFailedLogsErrorWithException(): void
    {
        $entry = self::createStub(Entry::class);
        $exception = new Exception('Sync failed');

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Entry sync to JIRA failed', ['exception' => $exception]);

        $event = new EntryEvent($entry, ['exception' => $exception]);
        $this->subscriber->onEntrySyncFailed($event);
    }

    public function testOnEntrySyncFailedLogsErrorWithoutException(): void
    {
        $entry = self::createStub(Entry::class);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Entry sync to JIRA failed');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntrySyncFailed($event);
    }

    public function testOnEntrySyncFailedLogsErrorWhenContextExceptionIsNotThrowable(): void
    {
        $entry = self::createStub(Entry::class);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Entry sync to JIRA failed');

        $event = new EntryEvent($entry, ['exception' => 'not a throwable']);
        $this->subscriber->onEntrySyncFailed($event);
    }

    public function testConstructorWithoutLogger(): void
    {
        $subscriber = new EntryEventSubscriber(
            $this->jiraOAuthApiFactory,
            $this->managerRegistry,
            $this->queryCacheService,
            $this->worklogWriteService,
        );

        // Just verify it doesn't throw - logger is optional
        $entry = self::createStub(Entry::class);
        $entry->method('getUser')->willReturn(null);
        $entry->method('getProject')->willReturn(null);

        $event = new EntryEvent($entry);
        $subscriber->onEntryCreated($event);

        // No exception thrown = success
        $this->expectNotToPerformAssertions();
    }

    /**
     * Builds a REAL entry on a project with an internal Jira ticket system
     * (the "internal ticket system" feature, see GH discussion in NRS-4188).
     *
     * @return array{Entry, User&Stub, TicketSystem&Stub}
     */
    private function createInternalProjectEntry(string $ticket, ?string $originalKey = null): array
    {
        $internalTicketSystem = self::createStub(TicketSystem::class);
        $internalTicketSystem->method('getBookTime')->willReturn(true);
        $internalTicketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = self::createStub(Project::class);
        $project->method('hasInternalJiraProjectKey')->willReturn(true);
        $project->method('getInternalJiraProjectKey')->willReturn('OPSDHL');
        $project->method('getInternalJiraTicketSystem')->willReturn('9');
        $project->method('getTicketSystem')->willReturn(null);

        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(1);

        $ticketSystemRepository = $this->createMock(TicketSystemRepository::class);
        $ticketSystemRepository->method('find')->willReturn($internalTicketSystem);
        $this->managerRegistry->method('getRepository')
            ->willReturn($ticketSystemRepository);

        $entry = new Entry();
        $entry->setUser($user);
        $entry->setProject($project);
        $entry->setTicket($ticket);
        if (null !== $originalKey) {
            $entry->setInternalJiraTicketOriginalKey($originalKey);
        }

        return [$entry, $user, $internalTicketSystem];
    }

    public function testInternalTicketSystemRemapsToExistingInternalIssue(): void
    {
        [$entry, $user, $internalTicketSystem] = $this->createInternalProjectEntry('DHLSUP-123456');

        $this->jiraOAuthApiFactory->expects(self::atLeastOnce())
            ->method('create')
            ->with($user, $internalTicketSystem)
            ->willReturn($this->jiraOAuthApiService);

        $this->jiraOAuthApiService->expects(self::once())
            ->method('searchTicket')
            ->with('project = OPSDHL AND summary ~ "DHLSUP-123456"', ['key', 'summary'], 1)
            ->willReturn((object) ['issues' => [(object) ['key' => 'OPSDHL-75']]]);

        $this->jiraOAuthApiService->expects(self::never())
            ->method('createTicket');

        $this->worklogWriteService->expects(self::once())
            ->method('push')
            ->with($this->jiraOAuthApiService, $entry, self::anything())
            ->willReturn(WriteOutcome::WRITTEN);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);

        self::assertSame('OPSDHL-75', $entry->getTicket());
        self::assertSame('DHLSUP-123456', $entry->getInternalJiraTicketOriginalKey());
    }

    public function testInternalTicketSystemCreatesMissingInternalIssue(): void
    {
        [$entry] = $this->createInternalProjectEntry('DHLSUP-7');

        $this->jiraOAuthApiFactory->method('create')
            ->willReturn($this->jiraOAuthApiService);

        $this->jiraOAuthApiService->method('searchTicket')
            ->willReturn((object) ['issues' => []]);

        $this->jiraOAuthApiService->expects(self::once())
            ->method('createTicket')
            ->with($entry)
            ->willReturn((object) ['key' => 'OPSDHL-99']);

        $this->worklogWriteService->expects(self::once())
            ->method('push')
            ->with($this->jiraOAuthApiService, $entry, self::anything())
            ->willReturn(WriteOutcome::WRITTEN);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);

        self::assertSame('OPSDHL-99', $entry->getTicket());
        self::assertSame('DHLSUP-7', $entry->getInternalJiraTicketOriginalKey());
    }

    public function testInternalTicketSystemUsesOriginalKeyForMirroredEntries(): void
    {
        // an already-mirrored entry carries the internal key as its ticket
        // and the external key in internalJiraTicketOriginalKey
        [$entry] = $this->createInternalProjectEntry('OPSDHL-75', 'DHLSUP-123456');

        $this->jiraOAuthApiFactory->method('create')
            ->willReturn($this->jiraOAuthApiService);

        $this->jiraOAuthApiService->expects(self::once())
            ->method('searchTicket')
            ->with('project = OPSDHL AND summary ~ "DHLSUP-123456"', ['key', 'summary'], 1)
            ->willReturn((object) ['issues' => [(object) ['key' => 'OPSDHL-75']]]);

        $this->worklogWriteService->method('push')
            ->willReturn(WriteOutcome::WRITTEN);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);

        self::assertSame('OPSDHL-75', $entry->getTicket());
        self::assertSame('DHLSUP-123456', $entry->getInternalJiraTicketOriginalKey());
    }

    public function testInternalTicketSystemMalformedJiraResponseIsCaughtAndLogged(): void
    {
        [$entry] = $this->createInternalProjectEntry('DHLSUP-8');

        $this->jiraOAuthApiFactory->method('create')
            ->willReturn($this->jiraOAuthApiService);

        // malformed response: issue without a usable key
        $this->jiraOAuthApiService->method('searchTicket')
            ->willReturn((object) ['issues' => [(object) ['key' => '']]]);

        $this->worklogWriteService->expects(self::never())
            ->method('push');

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Auto-sync to JIRA failed', self::anything());

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);

        // entry stays unmapped
        self::assertSame('DHLSUP-8', $entry->getTicket());
        self::assertNull($entry->getInternalJiraTicketOriginalKey());
    }

    public function testInternalTicketSystemSkippedWhenInternalSystemNotBookable(): void
    {
        $internalTicketSystem = self::createStub(TicketSystem::class);
        $internalTicketSystem->method('getBookTime')->willReturn(false);

        $project = self::createStub(Project::class);
        $project->method('hasInternalJiraProjectKey')->willReturn(true);
        $project->method('getInternalJiraTicketSystem')->willReturn('9');
        $project->method('getTicketSystem')->willReturn(null);

        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(1);

        $ticketSystemRepository = $this->createMock(TicketSystemRepository::class);
        $ticketSystemRepository->method('find')->willReturn($internalTicketSystem);
        $this->managerRegistry->method('getRepository')->willReturn($ticketSystemRepository);

        $entry = new Entry();
        $entry->setUser($user);
        $entry->setProject($project);
        $entry->setTicket('DHLSUP-1');

        $this->jiraOAuthApiFactory->expects(self::never())
            ->method('create');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);

        self::assertSame('DHLSUP-1', $entry->getTicket());
    }

    public function testOnEntryUpdatedDeletesPreviousWorklogWhenTicketChanged(): void
    {
        $ticketSystem = self::createStub(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = self::createStub(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(1);

        $entry = new Entry();
        $entry->setUser($user);
        $entry->setProject($project);
        $entry->setTicket('ABC-2');
        $entry->setWorklogId(555);

        $previousEntry = new Entry();
        $previousEntry->setTicket('ABC-1');
        $previousEntry->setWorklogId(555);

        $this->jiraOAuthApiFactory->method('create')
            ->willReturn($this->jiraOAuthApiService);

        $this->worklogWriteService->expects(self::once())
            ->method('delete')
            ->with($this->jiraOAuthApiService, $previousEntry);

        $this->worklogWriteService->expects(self::once())
            ->method('push')
            ->with($this->jiraOAuthApiService, $entry, self::anything())
            ->willReturn(WriteOutcome::WRITTEN);

        $event = new EntryEvent($entry, ['previous' => $previousEntry]);
        $this->subscriber->onEntryUpdated($event);

        self::assertNull($entry->getWorklogId());
    }

    public function testLeaseLostLogsParkedConflictAndDoesNotThrow(): void
    {
        [$entry, $user, $ticketSystem] = $this->createSyncableEntry(synced: true, worklogId: 12345);

        $this->expectJiraApiCreatedFor($user, $ticketSystem);

        $this->worklogWriteService->expects(self::once())
            ->method('push')
            ->with($this->jiraOAuthApiService, $entry, self::anything())
            ->willReturn(WriteOutcome::LEASE_LOST);

        $this->objectManager->expects(self::once())
            ->method('flush');

        $messages = [];
        $this->logger->method('info')
            ->willReturnCallback(static function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
        $this->logger->expects(self::never())
            ->method('error');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);

        $parked = array_values(array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, 'parked as conflict'),
        ));
        self::assertCount(1, $parked);
    }

    public function testRemoteMissingLogsOrphaned(): void
    {
        [$entry, $user, $ticketSystem] = $this->createSyncableEntry(synced: true, worklogId: 12345);

        $this->expectJiraApiCreatedFor($user, $ticketSystem);

        $this->worklogWriteService->expects(self::once())
            ->method('push')
            ->with($this->jiraOAuthApiService, $entry, self::anything())
            ->willReturn(WriteOutcome::REMOTE_MISSING);

        $messages = [];
        $this->logger->method('info')
            ->willReturnCallback(static function (string $message) use (&$messages): void {
                $messages[] = $message;
            });

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);

        $orphaned = array_values(array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, 'orphaned'),
        ));
        self::assertCount(1, $orphaned);
    }
}

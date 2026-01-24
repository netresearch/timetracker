<?php

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\TicketSystemType;
use App\Event\EntryEvent;
use App\EventSubscriber\EntryEventSubscriber;
use App\Service\Cache\QueryCacheService;
use App\Service\Integration\Jira\JiraIntegrationService;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(EntryEventSubscriber::class)]
final class EntryEventSubscriberTest extends TestCase
{
    private JiraIntegrationService&MockObject $jiraIntegrationService;
    private QueryCacheService&MockObject $queryCacheService;
    private LoggerInterface&MockObject $logger;
    private EntryEventSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->jiraIntegrationService = $this->createMock(JiraIntegrationService::class);
        $this->queryCacheService = $this->createMock(QueryCacheService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new EntryEventSubscriber(
            $this->jiraIntegrationService,
            $this->queryCacheService,
            $this->logger,
        );
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
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $entry = $this->createMock(Entry::class);
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
        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn(null);
        $entry->method('getProject')->willReturn(null);

        $this->queryCacheService->expects(self::never())
            ->method('invalidateEntity');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedAutoSyncsToJiraWhenConditionsMet(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getTicket')->willReturn('ABC-123');

        $this->jiraIntegrationService->expects(self::once())
            ->method('saveWorklog')
            ->with($entry);

        $this->logger->expects(self::exactly(2))
            ->method('info');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenNoProject(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn(null);

        $this->jiraIntegrationService->expects(self::never())
            ->method('saveWorklog');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenNoTicketSystem(): void
    {
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn(null);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);

        $this->jiraIntegrationService->expects(self::never())
            ->method('saveWorklog');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenBookTimeDisabled(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(false);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);

        $this->jiraIntegrationService->expects(self::never())
            ->method('saveWorklog');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenNotJiraTicketSystem(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::OTRS);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);

        $this->jiraIntegrationService->expects(self::never())
            ->method('saveWorklog');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenEmptyTicket(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getTicket')->willReturn('');

        $this->jiraIntegrationService->expects(self::never())
            ->method('saveWorklog');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedDoesNotSyncWhenZeroTicket(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getTicket')->willReturn('0');

        $this->jiraIntegrationService->expects(self::never())
            ->method('saveWorklog');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryCreatedLogsErrorOnJiraSyncFailure(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getTicket')->willReturn('ABC-123');

        $exception = new Exception('Jira API error');
        $this->jiraIntegrationService->expects(self::once())
            ->method('saveWorklog')
            ->willThrowException($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Auto-sync to JIRA failed', ['exception' => $exception]);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryCreated($event);
    }

    public function testOnEntryUpdatedInvalidatesCache(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getSyncedToTicketsystem')->willReturn(false);

        $this->queryCacheService->expects(self::once())
            ->method('invalidateEntity')
            ->with(Entry::class, 42);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);
    }

    public function testOnEntryUpdatedSyncsToJiraWhenAlreadySynced(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getSyncedToTicketsystem')->willReturn(true);
        $entry->method('getWorklogId')->willReturn(12345);

        $this->jiraIntegrationService->expects(self::once())
            ->method('saveWorklog')
            ->with($entry);

        $this->logger->expects(self::exactly(2))
            ->method('info');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);
    }

    public function testOnEntryUpdatedDoesNotSyncWhenNotPreviouslySynced(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getSyncedToTicketsystem')->willReturn(false);

        $this->jiraIntegrationService->expects(self::never())
            ->method('saveWorklog');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);
    }

    public function testOnEntryUpdatedDoesNotSyncWhenNoWorklogId(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getSyncedToTicketsystem')->willReturn(true);
        $entry->method('getWorklogId')->willReturn(null);

        $this->jiraIntegrationService->expects(self::never())
            ->method('saveWorklog');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);
    }

    public function testOnEntryUpdatedLogsWarningOnJiraFailure(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getSyncedToTicketsystem')->willReturn(true);
        $entry->method('getWorklogId')->willReturn(12345);

        $exception = new Exception('Jira API error');
        $this->jiraIntegrationService->expects(self::once())
            ->method('saveWorklog')
            ->willThrowException($exception);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('JIRA worklog update failed', ['exception' => $exception]);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryUpdated($event);
    }

    public function testOnEntryDeletedInvalidatesCache(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $entry = $this->createMock(Entry::class);
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
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getSyncedToTicketsystem')->willReturn(true);
        $entry->method('getWorklogId')->willReturn(12345);

        $this->jiraIntegrationService->expects(self::once())
            ->method('deleteWorklog')
            ->with($entry);

        $this->logger->expects(self::exactly(2))
            ->method('info');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryDeleted($event);
    }

    public function testOnEntryDeletedDoesNotDeleteWhenNotSynced(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getSyncedToTicketsystem')->willReturn(false);

        $this->jiraIntegrationService->expects(self::never())
            ->method('deleteWorklog');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryDeleted($event);
    }

    public function testOnEntryDeletedDoesNotDeleteWhenNoWorklogId(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getSyncedToTicketsystem')->willReturn(true);
        $entry->method('getWorklogId')->willReturn(null);

        $this->jiraIntegrationService->expects(self::never())
            ->method('deleteWorklog');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryDeleted($event);
    }

    public function testOnEntryDeletedLogsWarningOnFailure(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getSyncedToTicketsystem')->willReturn(true);
        $entry->method('getWorklogId')->willReturn(12345);

        $exception = new Exception('Jira API error');
        $this->jiraIntegrationService->expects(self::once())
            ->method('deleteWorklog')
            ->willThrowException($exception);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('JIRA worklog deletion failed', ['exception' => $exception]);

        $event = new EntryEvent($entry);
        $this->subscriber->onEntryDeleted($event);
    }

    public function testOnEntrySyncedInvalidatesJiraSyncCacheTag(): void
    {
        $entry = $this->createMock(Entry::class);

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
        $entry = $this->createMock(Entry::class);
        $exception = new Exception('Sync failed');

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Entry sync to JIRA failed', ['exception' => $exception]);

        $event = new EntryEvent($entry, ['exception' => $exception]);
        $this->subscriber->onEntrySyncFailed($event);
    }

    public function testOnEntrySyncFailedLogsErrorWithoutException(): void
    {
        $entry = $this->createMock(Entry::class);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Entry sync to JIRA failed');

        $event = new EntryEvent($entry);
        $this->subscriber->onEntrySyncFailed($event);
    }

    public function testOnEntrySyncFailedLogsErrorWhenContextExceptionIsNotThrowable(): void
    {
        $entry = $this->createMock(Entry::class);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Entry sync to JIRA failed');

        $event = new EntryEvent($entry, ['exception' => 'not a throwable']);
        $this->subscriber->onEntrySyncFailed($event);
    }

    public function testConstructorWithoutLogger(): void
    {
        $subscriber = new EntryEventSubscriber(
            $this->jiraIntegrationService,
            $this->queryCacheService,
        );

        // Just verify it doesn't throw - logger is optional
        $entry = $this->createMock(Entry::class);
        $entry->method('getUser')->willReturn(null);
        $entry->method('getProject')->willReturn(null);

        $event = new EntryEvent($entry);
        $subscriber->onEntryCreated($event);

        // No exception thrown = success
        $this->expectNotToPerformAssertions();
    }
}

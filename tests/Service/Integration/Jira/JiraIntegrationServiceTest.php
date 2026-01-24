<?php

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\TicketSystemType;
use App\Exception\Integration\Jira\JiraApiException;
use App\Repository\EntryRepository;
use App\Repository\TicketSystemRepository;
use App\Service\Integration\Jira\JiraIntegrationService;
use App\Service\Integration\Jira\JiraWorkLogService;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(JiraIntegrationService::class)]
final class JiraIntegrationServiceTest extends TestCase
{
    private ManagerRegistry&MockObject $managerRegistry;
    private JiraWorkLogService&MockObject $jiraWorkLogService;
    private LoggerInterface&MockObject $logger;
    private JiraIntegrationService $service;

    protected function setUp(): void
    {
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
        $this->jiraWorkLogService = $this->createMock(JiraWorkLogService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new JiraIntegrationService(
            $this->managerRegistry,
            $this->jiraWorkLogService,
            $this->logger,
        );
    }

    // ========== saveWorklog tests ==========

    public function testSaveWorklogReturnsFalseWhenNoProject(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getProject')->willReturn(null);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('No project associated with entry');

        $result = $this->service->saveWorklog($entry);

        self::assertFalse($result);
    }

    public function testSaveWorklogReturnsFalseWhenShouldNotSync(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(false);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $entry = $this->createMock(Entry::class);
        $entry->method('getProject')->willReturn($project);

        $this->logger->expects(self::once())
            ->method('debug')
            ->with('Entry should not sync with JIRA');

        $result = $this->service->saveWorklog($entry);

        self::assertFalse($result);
    }

    public function testSaveWorklogThrowsExceptionWhenNoUser(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $entry = $this->createMock(Entry::class);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getUser')->willReturn(null);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getStart')->willReturn(new DateTime());
        $entry->method('getEnd')->willReturn(new DateTime('+1 hour'));

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Entry has no associated user');

        $this->service->saveWorklog($entry);
    }

    public function testSaveWorklogSucceeds(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $user = $this->createMock(User::class);

        $entry = $this->createMock(Entry::class);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getStart')->willReturn(new DateTime());
        $entry->method('getEnd')->willReturn(new DateTime('+1 hour'));

        $this->jiraWorkLogService->expects(self::once())
            ->method('updateEntryWorkLog')
            ->with($entry);

        $this->logger->expects(self::once())
            ->method('info')
            ->with('JIRA worklog synced');

        $result = $this->service->saveWorklog($entry);

        self::assertTrue($result);
    }

    public function testSaveWorklogRethrowsException(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $user = $this->createMock(User::class);

        $entry = $this->createMock(Entry::class);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getStart')->willReturn(new DateTime());
        $entry->method('getEnd')->willReturn(new DateTime('+1 hour'));

        $exception = new Exception('API Error');
        $this->jiraWorkLogService->expects(self::once())
            ->method('updateEntryWorkLog')
            ->willThrowException($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('JIRA sync failed', ['exception' => $exception]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('API Error');

        $this->service->saveWorklog($entry);
    }

    public function testSaveWorklogUsesInternalJiraTicketSystem(): void
    {
        $internalTicketSystem = $this->createMock(TicketSystem::class);
        $internalTicketSystem->method('getBookTime')->willReturn(true);
        $internalTicketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $ticketSystemRepo = $this->createMock(TicketSystemRepository::class);
        $ticketSystemRepo->method('find')->with('99')->willReturn($internalTicketSystem);

        $this->managerRegistry->method('getRepository')
            ->with(TicketSystem::class)
            ->willReturn($ticketSystemRepo);

        $project = $this->createMock(Project::class);
        $project->method('hasInternalJiraProjectKey')->willReturn(true);
        $project->method('getInternalJiraTicketSystem')->willReturn('99');

        $user = $this->createMock(User::class);

        $entry = $this->createMock(Entry::class);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getTicket')->willReturn('INT-123');
        $entry->method('getStart')->willReturn(new DateTime());
        $entry->method('getEnd')->willReturn(new DateTime('+1 hour'));

        $this->jiraWorkLogService->expects(self::once())
            ->method('updateEntryWorkLog');

        $result = $this->service->saveWorklog($entry);

        self::assertTrue($result);
    }

    // ========== deleteWorklog tests ==========

    public function testDeleteWorklogReturnsFalseWhenNoWorklogId(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getWorklogId')->willReturn(null);

        $this->logger->expects(self::once())
            ->method('info')
            ->with('Entry has no worklog ID to delete');

        $result = $this->service->deleteWorklog($entry);

        self::assertFalse($result);
    }

    public function testDeleteWorklogThrowsExceptionWhenNoProject(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getWorklogId')->willReturn(12345);
        $entry->method('getProject')->willReturn(null);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Entry has no associated project');

        $this->service->deleteWorklog($entry);
    }

    public function testDeleteWorklogThrowsExceptionWhenNoTicketSystem(): void
    {
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn(null);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $entry = $this->createMock(Entry::class);
        $entry->method('getWorklogId')->willReturn(12345);
        $entry->method('getProject')->willReturn($project);

        $this->managerRegistry->method('getRepository')
            ->willReturn($this->createMock(TicketSystemRepository::class));

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Project has no ticket system configured');

        $this->service->deleteWorklog($entry);
    }

    public function testDeleteWorklogThrowsExceptionWhenNoUser(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $entry = $this->createMock(Entry::class);
        $entry->method('getWorklogId')->willReturn(12345);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getUser')->willReturn(null);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Entry has no associated user');

        $this->service->deleteWorklog($entry);
    }

    public function testDeleteWorklogSucceeds(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $user = $this->createMock(User::class);

        $entry = $this->createMock(Entry::class);
        $entry->method('getWorklogId')->willReturn(12345);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getUser')->willReturn($user);
        $entry->expects(self::once())->method('setWorklogId')->with(null);
        $entry->expects(self::once())->method('setSyncedToTicketsystem')->with(false);

        $objectManager = $this->createMock(ObjectManager::class);
        $objectManager->expects(self::once())->method('persist')->with($entry);
        $objectManager->expects(self::once())->method('flush');

        $this->managerRegistry->method('getManager')->willReturn($objectManager);

        $this->jiraWorkLogService->expects(self::once())
            ->method('deleteEntryWorkLog')
            ->with($entry);

        $this->logger->expects(self::once())
            ->method('info')
            ->with('JIRA worklog deleted');

        $result = $this->service->deleteWorklog($entry);

        self::assertTrue($result);
    }

    public function testDeleteWorklogRethrowsException(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $user = $this->createMock(User::class);

        $entry = $this->createMock(Entry::class);
        $entry->method('getWorklogId')->willReturn(12345);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getUser')->willReturn($user);

        $exception = new Exception('Delete failed');
        $this->jiraWorkLogService->expects(self::once())
            ->method('deleteEntryWorkLog')
            ->willThrowException($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('JIRA worklog deletion failed', ['exception' => $exception]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Delete failed');

        $this->service->deleteWorklog($entry);
    }

    // ========== batchSyncWorkLogs tests ==========

    public function testBatchSyncWorkLogsProcessesAllEntries(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $user = $this->createMock(User::class);

        $entry1 = $this->createMock(Entry::class);
        $entry1->method('getId')->willReturn(1);
        $entry1->method('getProject')->willReturn($project);
        $entry1->method('getUser')->willReturn($user);
        $entry1->method('getTicket')->willReturn('ABC-1');
        $entry1->method('getStart')->willReturn(new DateTime());
        $entry1->method('getEnd')->willReturn(new DateTime('+1 hour'));

        $entry2 = $this->createMock(Entry::class);
        $entry2->method('getId')->willReturn(2);
        $entry2->method('getProject')->willReturn($project);
        $entry2->method('getUser')->willReturn($user);
        $entry2->method('getTicket')->willReturn('ABC-2');
        $entry2->method('getStart')->willReturn(new DateTime());
        $entry2->method('getEnd')->willReturn(new DateTime('+1 hour'));

        $this->jiraWorkLogService->expects(self::exactly(2))
            ->method('updateEntryWorkLog');

        $results = $this->service->batchSyncWorkLogs([$entry1, $entry2]);

        self::assertCount(2, $results);
        self::assertTrue($results[1]);
        self::assertTrue($results[2]);
    }

    public function testBatchSyncWorkLogsContinuesOnFailure(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $user = $this->createMock(User::class);

        $entry1 = $this->createMock(Entry::class);
        $entry1->method('getId')->willReturn(1);
        $entry1->method('getProject')->willReturn($project);
        $entry1->method('getUser')->willReturn($user);
        $entry1->method('getTicket')->willReturn('ABC-1');
        $entry1->method('getStart')->willReturn(new DateTime());
        $entry1->method('getEnd')->willReturn(new DateTime('+1 hour'));

        $entry2 = $this->createMock(Entry::class);
        $entry2->method('getId')->willReturn(2);
        $entry2->method('getProject')->willReturn($project);
        $entry2->method('getUser')->willReturn($user);
        $entry2->method('getTicket')->willReturn('ABC-2');
        $entry2->method('getStart')->willReturn(new DateTime());
        $entry2->method('getEnd')->willReturn(new DateTime('+1 hour'));

        $this->jiraWorkLogService->expects(self::exactly(2))
            ->method('updateEntryWorkLog')
            ->willReturnCallback(static function (Entry $entry): void {
                if (1 === $entry->getId()) {
                    throw new Exception('Sync failed');
                }
            });

        $results = $this->service->batchSyncWorkLogs([$entry1, $entry2]);

        self::assertCount(2, $results);
        self::assertFalse($results[1]);
        self::assertTrue($results[2]);
    }

    public function testBatchSyncWorkLogsSkipsNonEntries(): void
    {
        // @phpstan-ignore argument.type (intentionally testing with invalid input)
        $results = $this->service->batchSyncWorkLogs(['not an entry', null, 123]);

        self::assertCount(0, $results);
    }

    // ========== getEntriesNeedingSync tests ==========

    public function testGetEntriesNeedingSyncReturnsFilteredEntries(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $entry1 = $this->createMock(Entry::class);
        $entry1->method('getProject')->willReturn($project);
        $entry1->method('getTicket')->willReturn('ABC-1');
        $entry1->method('getStart')->willReturn(new DateTime());
        $entry1->method('getEnd')->willReturn(new DateTime('+1 hour'));

        $entry2 = $this->createMock(Entry::class);
        $entry2->method('getProject')->willReturn(null);

        $entryRepo = $this->createMock(EntryRepository::class);
        $entryRepo->method('findBy')
            ->with(['syncedToTicketsystem' => false])
            ->willReturn([$entry1, $entry2]);

        $this->managerRegistry->method('getRepository')
            ->with(Entry::class)
            ->willReturn($entryRepo);

        $entries = $this->service->getEntriesNeedingSync();

        self::assertCount(1, $entries);
        self::assertSame($entry1, $entries[0]);
    }

    public function testGetEntriesNeedingSyncFiltersWithUserAndSince(): void
    {
        $user = $this->createMock(User::class);
        $since = new DateTime('-1 week');

        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $entry1 = $this->createMock(Entry::class);
        $entry1->method('getProject')->willReturn($project);
        $entry1->method('getTicket')->willReturn('ABC-1');
        $entry1->method('getStart')->willReturn(new DateTime());
        $entry1->method('getEnd')->willReturn(new DateTime('+1 hour'));

        $entry2 = $this->createMock(Entry::class);
        $entry2->method('getProject')->willReturn($project);
        $entry2->method('getTicket')->willReturn('ABC-2');
        $entry2->method('getStart')->willReturn(new DateTime('-2 weeks'));
        $entry2->method('getEnd')->willReturn(new DateTime('-2 weeks +1 hour'));

        $entryRepo = $this->createMock(EntryRepository::class);
        $entryRepo->method('findBy')
            ->with(['syncedToTicketsystem' => false, 'user' => $user])
            ->willReturn([$entry1, $entry2]);

        $this->managerRegistry->method('getRepository')
            ->with(Entry::class)
            ->willReturn($entryRepo);

        $entries = $this->service->getEntriesNeedingSync($user, $since);

        self::assertCount(1, $entries);
        self::assertSame($entry1, $entries[0]);
    }

    public function testGetEntriesNeedingSyncFiltersEmptyTickets(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $entry = $this->createMock(Entry::class);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getTicket')->willReturn('');
        $entry->method('getStart')->willReturn(new DateTime());
        $entry->method('getEnd')->willReturn(new DateTime('+1 hour'));

        $entryRepo = $this->createMock(EntryRepository::class);
        $entryRepo->method('findBy')->willReturn([$entry]);

        $this->managerRegistry->method('getRepository')
            ->with(Entry::class)
            ->willReturn($entryRepo);

        $entries = $this->service->getEntriesNeedingSync();

        self::assertCount(0, $entries);
    }

    public function testGetEntriesNeedingSyncFiltersZeroDuration(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getBookTime')->willReturn(true);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('hasInternalJiraProjectKey')->willReturn(false);

        $now = new DateTime();
        $entry = $this->createMock(Entry::class);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getTicket')->willReturn('ABC-1');
        $entry->method('getStart')->willReturn($now);
        $entry->method('getEnd')->willReturn($now);  // Same time = 0 duration

        $entryRepo = $this->createMock(EntryRepository::class);
        $entryRepo->method('findBy')->willReturn([$entry]);

        $this->managerRegistry->method('getRepository')
            ->with(Entry::class)
            ->willReturn($entryRepo);

        $entries = $this->service->getEntriesNeedingSync();

        self::assertCount(0, $entries);
    }

    // ========== validateJiraConnection tests ==========

    public function testValidateJiraConnectionReturnsFalseForNonJira(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::OTRS);

        $user = $this->createMock(User::class);

        $result = $this->service->validateJiraConnection($ticketSystem, $user);

        self::assertFalse($result);
    }

    public function testValidateJiraConnectionReturnsTrue(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $user = $this->createMock(User::class);

        $this->jiraWorkLogService->expects(self::once())
            ->method('validateConnection')
            ->with($user, $ticketSystem)
            ->willReturn(true);

        $result = $this->service->validateJiraConnection($ticketSystem, $user);

        self::assertTrue($result);
    }

    public function testValidateJiraConnectionReturnsFalseOnException(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $user = $this->createMock(User::class);

        $this->jiraWorkLogService->expects(self::once())
            ->method('validateConnection')
            ->willThrowException(new Exception('Connection failed'));

        $this->logger->expects(self::once())
            ->method('error');

        $result = $this->service->validateJiraConnection($ticketSystem, $user);

        self::assertFalse($result);
    }

    // ========== getJiraProjectInfo tests ==========

    public function testGetJiraProjectInfoReturnsNullForNonJira(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::OTRS);

        $user = $this->createMock(User::class);

        $result = $this->service->getJiraProjectInfo('PROJ', $ticketSystem, $user);

        self::assertNull($result);
    }

    public function testGetJiraProjectInfoReturnsProjectData(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $user = $this->createMock(User::class);

        $projectInfo = ['key' => 'PROJ', 'name' => 'Project'];
        $this->jiraWorkLogService->expects(self::once())
            ->method('getProjectInfo')
            ->with('PROJ', $user, $ticketSystem)
            ->willReturn($projectInfo);

        $result = $this->service->getJiraProjectInfo('PROJ', $ticketSystem, $user);

        self::assertSame($projectInfo, $result);
    }

    public function testGetJiraProjectInfoReturnsNullOnException(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getType')->willReturn(TicketSystemType::JIRA);

        $user = $this->createMock(User::class);

        $this->jiraWorkLogService->expects(self::once())
            ->method('getProjectInfo')
            ->willThrowException(new Exception('API error'));

        $this->logger->expects(self::once())
            ->method('error');

        $result = $this->service->getJiraProjectInfo('PROJ', $ticketSystem, $user);

        self::assertNull($result);
    }

    public function testConstructorWithoutLogger(): void
    {
        $service = new JiraIntegrationService(
            $this->managerRegistry,
            $this->jiraWorkLogService,
        );

        // Test that it works without logger
        $entry = $this->createMock(Entry::class);
        $entry->method('getProject')->willReturn(null);

        $result = $service->saveWorklog($entry);

        self::assertFalse($result);
    }
}

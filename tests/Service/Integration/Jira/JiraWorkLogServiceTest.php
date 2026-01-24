<?php

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiInvalidResourceException;
use App\Repository\EntryRepository;
use App\Service\Integration\Jira\JiraAuthenticationService;
use App\Service\Integration\Jira\JiraHttpClientService;
use App\Service\Integration\Jira\JiraTicketService;
use App\Service\Integration\Jira\JiraWorkLogService;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

use function is_string;

/**
 * @internal
 */
#[CoversClass(JiraWorkLogService::class)]
final class JiraWorkLogServiceTest extends TestCase
{
    private ManagerRegistry&MockObject $managerRegistry;
    private JiraHttpClientService&MockObject $jiraHttpClientService;
    private JiraTicketService&MockObject $jiraTicketService;
    private JiraAuthenticationService&MockObject $jiraAuthenticationService;
    private JiraWorkLogService $service;

    protected function setUp(): void
    {
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
        $this->jiraHttpClientService = $this->createMock(JiraHttpClientService::class);
        $this->jiraTicketService = $this->createMock(JiraTicketService::class);
        $this->jiraAuthenticationService = $this->createMock(JiraAuthenticationService::class);

        $this->service = new JiraWorkLogService(
            $this->managerRegistry,
            $this->jiraHttpClientService,
            $this->jiraTicketService,
            $this->jiraAuthenticationService,
        );
    }

    // ========== updateEntryWorkLog tests ==========

    public function testUpdateEntryWorkLogSkipsEmptyTicket(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('');

        $this->jiraHttpClientService->expects(self::never())
            ->method('post');

        $this->service->updateEntryWorkLog($entry);
    }

    public function testUpdateEntryWorkLogSkipsZeroTicket(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('0');

        $this->jiraHttpClientService->expects(self::never())
            ->method('post');

        $this->service->updateEntryWorkLog($entry);
    }

    public function testUpdateEntryWorkLogSkipsWhenNoUser(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn(null);

        $this->jiraHttpClientService->expects(self::never())
            ->method('post');

        $this->service->updateEntryWorkLog($entry);
    }

    public function testUpdateEntryWorkLogSkipsWhenNoProject(): void
    {
        $user = $this->createMock(User::class);

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn(null);

        $this->jiraHttpClientService->expects(self::never())
            ->method('post');

        $this->service->updateEntryWorkLog($entry);
    }

    public function testUpdateEntryWorkLogSkipsWhenNoTicketSystem(): void
    {
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn(null);

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);

        $this->jiraHttpClientService->expects(self::never())
            ->method('post');

        $this->service->updateEntryWorkLog($entry);
    }

    public function testUpdateEntryWorkLogSkipsWhenUserNotAuthenticated(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);

        $this->jiraAuthenticationService->expects(self::once())
            ->method('checkUserTicketSystem')
            ->with($user, $ticketSystem)
            ->willReturn(false);

        $this->jiraHttpClientService->expects(self::never())
            ->method('post');

        $this->service->updateEntryWorkLog($entry);
    }

    public function testUpdateEntryWorkLogSkipsWhenTicketDoesNotExist(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);
        $this->jiraTicketService->expects(self::once())
            ->method('doesTicketExist')
            ->with('ABC-123')
            ->willReturn(false);

        $this->jiraHttpClientService->expects(self::never())
            ->method('post');

        $this->service->updateEntryWorkLog($entry);
    }

    public function testUpdateEntryWorkLogDeletesWhenZeroDuration(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getDuration')->willReturn(0);
        $entry->method('getWorklogId')->willReturn(12345);

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);
        $this->jiraTicketService->method('doesTicketExist')->willReturn(true);

        // Should check if worklog exists and delete it
        $this->jiraHttpClientService->expects(self::once())
            ->method('doesResourceExist')
            ->with('issue/ABC-123/worklog/12345')
            ->willReturn(true);

        $this->jiraHttpClientService->expects(self::once())
            ->method('delete')
            ->with('issue/ABC-123/worklog/12345');

        $entry->expects(self::once())->method('setWorklogId')->with(null);
        $entry->expects(self::once())->method('setSyncedToTicketsystem')->with(false);

        $this->service->updateEntryWorkLog($entry);
    }

    public function testUpdateEntryWorkLogCreatesNewWorklog(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('getName')->willReturn('Test Project');

        $customer = $this->createMock(Customer::class);
        $customer->method('getName')->willReturn('Test Customer');

        $activity = $this->createMock(Activity::class);
        $activity->method('getName')->willReturn('Development');

        $day = new DateTime('2024-01-15');
        $start = new DateTime('2024-01-15 09:00:00');

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getCustomer')->willReturn($customer);
        $entry->method('getActivity')->willReturn($activity);
        $entry->method('getDuration')->willReturn(60);  // 60 minutes
        $entry->method('getWorklogId')->willReturn(null);
        $entry->method('getDay')->willReturn($day);
        $entry->method('getStart')->willReturn($start);
        $entry->method('getDescription')->willReturn('Working on feature');

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);
        $this->jiraTicketService->method('doesTicketExist')->willReturn(true);

        $response = new stdClass();
        $response->id = '99999';

        $this->jiraHttpClientService->expects(self::once())
            ->method('post')
            ->with(
                'issue/ABC-123/worklog',
                self::callback(static function (array $data): bool {
                    $started = $data['started'] ?? '';

                    return 'Test Customer | Test Project | Development | Working on feature' === $data['comment']
                           && 3600 === $data['timeSpentSeconds']
                           && is_string($started) && str_contains($started, '2024-01-15T09:00:00');
                }),
            )
            ->willReturn($response);

        $entry->expects(self::once())->method('setWorklogId')->with(99999);
        $entry->expects(self::once())->method('setSyncedToTicketsystem')->with(true);

        $this->service->updateEntryWorkLog($entry);
    }

    public function testUpdateEntryWorkLogUpdatesExistingWorklog(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('getName')->willReturn('Project');

        $day = new DateTime('2024-01-15');
        $start = new DateTime('2024-01-15 10:00:00');

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getCustomer')->willReturn(null);
        $entry->method('getActivity')->willReturn(null);
        $entry->method('getDuration')->willReturn(30);  // 30 minutes
        $entry->method('getWorklogId')->willReturn(12345);
        $entry->method('getDay')->willReturn($day);
        $entry->method('getStart')->willReturn($start);
        $entry->method('getDescription')->willReturn('');

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);
        $this->jiraTicketService->method('doesTicketExist')->willReturn(true);

        // Worklog exists check
        $this->jiraHttpClientService->method('doesResourceExist')
            ->with('issue/ABC-123/worklog/12345')
            ->willReturn(true);

        $response = new stdClass();
        $response->id = '12345';

        $this->jiraHttpClientService->expects(self::once())
            ->method('put')
            ->with(
                'issue/ABC-123/worklog/12345',
                self::callback(static fn (array $data): bool => 'Project | no description' === $data['comment']
                           && 1800 === $data['timeSpentSeconds']),
            )
            ->willReturn($response);

        $entry->expects(self::once())->method('setWorklogId')->with(12345);
        $entry->expects(self::once())->method('setSyncedToTicketsystem')->with(true);

        $this->service->updateEntryWorkLog($entry);
    }

    public function testUpdateEntryWorkLogClearsInvalidWorklogIdAndCreatesNew(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('getName')->willReturn('Project');

        $day = new DateTime('2024-01-15');
        $start = new DateTime('2024-01-15 10:00:00');

        // Track worklogId state
        $worklogId = 12345;

        $entry = $this->getMockBuilder(Entry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTicket', 'getUser', 'getProject', 'getCustomer', 'getActivity', 'getDuration', 'getDay', 'getStart', 'getDescription', 'getWorklogId', 'setWorklogId', 'setSyncedToTicketsystem'])
            ->getMock();

        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getCustomer')->willReturn(null);
        $entry->method('getActivity')->willReturn(null);
        $entry->method('getDuration')->willReturn(30);
        $entry->method('getDay')->willReturn($day);
        $entry->method('getStart')->willReturn($start);
        $entry->method('getDescription')->willReturn('Test');

        // First call returns old worklog ID for the check, after setWorklogId(null) it returns null
        $entry->method('getWorklogId')
            /** @phpstan-ignore return.unusedType (worklogId becomes null after setWorklogId(null)) */
            ->willReturnCallback(static function () use (&$worklogId): ?int {
                return $worklogId;
            });

        $entry->method('setWorklogId')
            ->willReturnCallback(static function (?int $id) use (&$worklogId, $entry): Entry {
                $worklogId = $id;

                return $entry;
            });

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);
        $this->jiraTicketService->method('doesTicketExist')->willReturn(true);

        // Worklog no longer exists in Jira
        $this->jiraHttpClientService->method('doesResourceExist')
            ->with('issue/ABC-123/worklog/12345')
            ->willReturn(false);

        $response = new stdClass();
        $response->id = '99999';

        // Should create new since old doesn't exist
        $this->jiraHttpClientService->expects(self::once())
            ->method('post')
            ->willReturn($response);

        $entry->expects(self::once())->method('setSyncedToTicketsystem')->with(true);

        $this->service->updateEntryWorkLog($entry);

        // Final state should be the new worklog ID
        self::assertSame(99999, $worklogId);
    }

    public function testUpdateEntryWorkLogThrowsOnInvalidResponse(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('getName')->willReturn('Project');

        $day = new DateTime('2024-01-15');
        $start = new DateTime('2024-01-15 10:00:00');

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getCustomer')->willReturn(null);
        $entry->method('getActivity')->willReturn(null);
        $entry->method('getDuration')->willReturn(30);
        $entry->method('getWorklogId')->willReturn(null);
        $entry->method('getDay')->willReturn($day);
        $entry->method('getStart')->willReturn($start);
        $entry->method('getDescription')->willReturn('Test');

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);
        $this->jiraTicketService->method('doesTicketExist')->willReturn(true);

        // Return non-object
        $this->jiraHttpClientService->method('post')
            ->willReturn('invalid response');

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Invalid response from Jira API when creating work log');

        $this->service->updateEntryWorkLog($entry);
    }

    public function testUpdateEntryWorkLogThrowsOnMissingWorklogId(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('getName')->willReturn('Project');

        $day = new DateTime('2024-01-15');
        $start = new DateTime('2024-01-15 10:00:00');

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getCustomer')->willReturn(null);
        $entry->method('getActivity')->willReturn(null);
        $entry->method('getDuration')->willReturn(30);
        $entry->method('getWorklogId')->willReturn(null);
        $entry->method('getDay')->willReturn($day);
        $entry->method('getStart')->willReturn($start);
        $entry->method('getDescription')->willReturn('Test');

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);
        $this->jiraTicketService->method('doesTicketExist')->willReturn(true);

        // Return object without id
        $response = new stdClass();
        $this->jiraHttpClientService->method('post')
            ->willReturn($response);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Unexpected response from Jira when updating worklog');

        $this->service->updateEntryWorkLog($entry);
    }

    // ========== deleteEntryWorkLog tests ==========

    public function testDeleteEntryWorkLogSkipsEmptyTicket(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('');

        $this->jiraHttpClientService->expects(self::never())
            ->method('delete');

        $this->service->deleteEntryWorkLog($entry);
    }

    public function testDeleteEntryWorkLogSkipsNullWorklogId(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getWorklogId')->willReturn(null);

        $this->jiraHttpClientService->expects(self::never())
            ->method('delete');

        $this->service->deleteEntryWorkLog($entry);
    }

    public function testDeleteEntryWorkLogSkipsWhenNoUser(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getWorklogId')->willReturn(12345);
        $entry->method('getUser')->willReturn(null);

        $this->jiraHttpClientService->expects(self::never())
            ->method('delete');

        $this->service->deleteEntryWorkLog($entry);
    }

    public function testDeleteEntryWorkLogSkipsWhenWorklogDoesNotExist(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getWorklogId')->willReturn(12345);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);

        $this->jiraHttpClientService->method('doesResourceExist')
            ->with('issue/ABC-123/worklog/12345')
            ->willReturn(false);

        $this->jiraHttpClientService->expects(self::never())
            ->method('delete');

        $entry->expects(self::once())->method('setWorklogId')->with(null);

        $this->service->deleteEntryWorkLog($entry);
    }

    public function testDeleteEntryWorkLogDeletesExistingWorklog(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getWorklogId')->willReturn(12345);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);

        $this->jiraHttpClientService->method('doesResourceExist')
            ->with('issue/ABC-123/worklog/12345')
            ->willReturn(true);

        $this->jiraHttpClientService->expects(self::once())
            ->method('delete')
            ->with('issue/ABC-123/worklog/12345');

        $entry->expects(self::once())->method('setWorklogId')->with(null);
        $entry->expects(self::once())->method('setSyncedToTicketsystem')->with(false);

        $this->service->deleteEntryWorkLog($entry);
    }

    public function testDeleteEntryWorkLogHandlesAlreadyDeletedWorklog(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getWorklogId')->willReturn(12345);
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);
        $this->jiraHttpClientService->method('doesResourceExist')->willReturn(true);

        $this->jiraHttpClientService->method('delete')
            ->willThrowException(new JiraApiInvalidResourceException('Not found'));

        $entry->expects(self::once())->method('setWorklogId')->with(null);
        $entry->expects(self::once())->method('setSyncedToTicketsystem')->with(false);

        $this->service->deleteEntryWorkLog($entry);
    }

    // ========== updateEntriesWorkLogsLimited tests ==========

    public function testUpdateEntriesWorkLogsLimitedSkipsWhenUserNotAuthenticated(): void
    {
        $user = $this->createMock(User::class);
        $ticketSystem = $this->createMock(TicketSystem::class);

        $this->jiraAuthenticationService->expects(self::once())
            ->method('checkUserTicketSystem')
            ->with($user, $ticketSystem)
            ->willReturn(false);

        $this->managerRegistry->expects(self::never())
            ->method('getRepository');

        $this->service->updateEntriesWorkLogsLimited($user, $ticketSystem);
    }

    public function testUpdateEntriesWorkLogsLimitedProcessesEntries(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getId')->willReturn(2);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('getName')->willReturn('Project');

        $day = new DateTime('2024-01-15');
        $start = new DateTime('2024-01-15 10:00:00');

        $entry = $this->createMock(Entry::class);
        $entry->method('getId')->willReturn(100);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getCustomer')->willReturn(null);
        $entry->method('getActivity')->willReturn(null);
        $entry->method('getDuration')->willReturn(60);
        $entry->method('getWorklogId')->willReturn(null);
        $entry->method('getDay')->willReturn($day);
        $entry->method('getStart')->willReturn($start);
        $entry->method('getDescription')->willReturn('Test');

        $entryRepo = $this->createMock(EntryRepository::class);
        $entryRepo->expects(self::once())
            ->method('findByUserAndTicketSystemToSync')
            ->with(1, 2, 50)
            ->willReturn([$entry]);

        $objectManager = $this->createMock(ObjectManager::class);
        $objectManager->expects(self::once())->method('persist')->with($entry);
        $objectManager->expects(self::once())->method('flush');

        $this->managerRegistry->method('getRepository')->willReturn($entryRepo);
        $this->managerRegistry->method('getManager')->willReturn($objectManager);

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);
        $this->jiraTicketService->method('doesTicketExist')->willReturn(true);

        $response = new stdClass();
        $response->id = '99999';
        $this->jiraHttpClientService->method('post')->willReturn($response);

        $this->service->updateEntriesWorkLogsLimited($user, $ticketSystem);
    }

    public function testUpdateEntriesWorkLogsLimitedContinuesOnError(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getId')->willReturn(2);

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('getName')->willReturn('Project');

        $day = new DateTime('2024-01-15');
        $start = new DateTime('2024-01-15 10:00:00');

        $entry1 = $this->createMock(Entry::class);
        $entry1->method('getId')->willReturn(100);
        $entry1->method('getTicket')->willReturn('ABC-123');
        $entry1->method('getUser')->willReturn($user);
        $entry1->method('getProject')->willReturn($project);
        $entry1->method('getCustomer')->willReturn(null);
        $entry1->method('getActivity')->willReturn(null);
        $entry1->method('getDuration')->willReturn(60);
        $entry1->method('getWorklogId')->willReturn(null);
        $entry1->method('getDay')->willReturn($day);
        $entry1->method('getStart')->willReturn($start);
        $entry1->method('getDescription')->willReturn('Test 1');

        $entry2 = $this->createMock(Entry::class);
        $entry2->method('getId')->willReturn(101);
        $entry2->method('getTicket')->willReturn('ABC-124');
        $entry2->method('getUser')->willReturn($user);
        $entry2->method('getProject')->willReturn($project);
        $entry2->method('getCustomer')->willReturn(null);
        $entry2->method('getActivity')->willReturn(null);
        $entry2->method('getDuration')->willReturn(60);
        $entry2->method('getWorklogId')->willReturn(null);
        $entry2->method('getDay')->willReturn($day);
        $entry2->method('getStart')->willReturn($start);
        $entry2->method('getDescription')->willReturn('Test 2');

        $entryRepo = $this->createMock(EntryRepository::class);
        $entryRepo->method('findByUserAndTicketSystemToSync')
            ->willReturn([$entry1, $entry2]);

        $objectManager = $this->createMock(ObjectManager::class);
        // persist called for entry2 only (entry1 throws)
        $objectManager->expects(self::once())->method('persist')->with($entry2);
        // flush called twice (once per entry in finally block)
        $objectManager->expects(self::exactly(2))->method('flush');

        $this->managerRegistry->method('getRepository')->willReturn($entryRepo);
        $this->managerRegistry->method('getManager')->willReturn($objectManager);

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);
        $this->jiraTicketService->method('doesTicketExist')->willReturn(true);

        $response = new stdClass();
        $response->id = '99999';

        // First call throws, second succeeds
        $this->jiraHttpClientService->method('post')
            ->willReturnCallback(static function (string $url) use ($response): stdClass {
                if (str_contains($url, 'ABC-123')) {
                    throw new JiraApiException('API Error');
                }

                return $response;
            });

        $this->service->updateEntriesWorkLogsLimited($user, $ticketSystem);
    }

    // ========== validateConnection tests ==========

    public function testValidateConnectionReturnsTrue(): void
    {
        $user = $this->createMock(User::class);
        $ticketSystem = $this->createMock(TicketSystem::class);

        $this->jiraAuthenticationService->expects(self::once())
            ->method('authenticate')
            ->with($user, $ticketSystem);

        $response = new stdClass();
        $response->name = 'testuser';

        $this->jiraHttpClientService->expects(self::once())
            ->method('get')
            ->with('myself')
            ->willReturn($response);

        $result = $this->service->validateConnection($user, $ticketSystem);

        self::assertTrue($result);
    }

    public function testValidateConnectionReturnsFalseOnInvalidResponse(): void
    {
        $user = $this->createMock(User::class);
        $ticketSystem = $this->createMock(TicketSystem::class);

        $this->jiraAuthenticationService->method('authenticate');

        // Return object without 'name' property
        $response = new stdClass();
        $this->jiraHttpClientService->method('get')
            ->with('myself')
            ->willReturn($response);

        $result = $this->service->validateConnection($user, $ticketSystem);

        self::assertFalse($result);
    }

    public function testValidateConnectionThrowsOnError(): void
    {
        $user = $this->createMock(User::class);
        $ticketSystem = $this->createMock(TicketSystem::class);

        $this->jiraAuthenticationService->method('authenticate')
            ->willThrowException(new Exception('Auth failed'));

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Jira connection validation failed: Auth failed');

        $this->service->validateConnection($user, $ticketSystem);
    }

    // ========== getProjectInfo tests ==========

    public function testGetProjectInfoReturnsProjectData(): void
    {
        $user = $this->createMock(User::class);
        $ticketSystem = $this->createMock(TicketSystem::class);

        $this->jiraAuthenticationService->expects(self::once())
            ->method('authenticate')
            ->with($user, $ticketSystem);

        $response = new stdClass();
        $response->key = 'PROJ';
        $response->name = 'Test Project';

        $this->jiraHttpClientService->expects(self::once())
            ->method('get')
            ->with('project/PROJ')
            ->willReturn($response);

        $result = $this->service->getProjectInfo('PROJ', $user, $ticketSystem);

        self::assertIsArray($result);
        self::assertArrayHasKey('key', $result);
        self::assertSame('PROJ', $result['key']);
    }

    public function testGetProjectInfoReturnsEmptyOnInvalidResponse(): void
    {
        $user = $this->createMock(User::class);
        $ticketSystem = $this->createMock(TicketSystem::class);

        $this->jiraAuthenticationService->method('authenticate');

        $this->jiraHttpClientService->method('get')
            ->willReturn('invalid');

        $result = $this->service->getProjectInfo('PROJ', $user, $ticketSystem);

        self::assertSame([], $result);
    }

    public function testGetProjectInfoThrowsOnError(): void
    {
        $user = $this->createMock(User::class);
        $ticketSystem = $this->createMock(TicketSystem::class);

        $this->jiraAuthenticationService->method('authenticate')
            ->willThrowException(new Exception('API error'));

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Failed to get Jira project info: API error');

        $this->service->getProjectInfo('PROJ', $user, $ticketSystem);
    }

    // ========== syncWorkLog tests ==========

    public function testSyncWorkLogReturnsWorklogId(): void
    {
        $user = $this->createMock(User::class);
        $ticketSystem = $this->createMock(TicketSystem::class);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('getName')->willReturn('Project');

        $day = new DateTime('2024-01-15');
        $start = new DateTime('2024-01-15 10:00:00');

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('ABC-123');
        $entry->method('getUser')->willReturn($user);
        $entry->method('getProject')->willReturn($project);
        $entry->method('getCustomer')->willReturn(null);
        $entry->method('getActivity')->willReturn(null);
        $entry->method('getDuration')->willReturn(60);
        $entry->method('getDay')->willReturn($day);
        $entry->method('getStart')->willReturn($start);
        $entry->method('getDescription')->willReturn('Test');
        $entry->method('getWorklogId')->willReturn(null, null, 99999);  // Called 3 times: check, create/update, return
        $entry->method('setWorklogId')->willReturnSelf();
        $entry->method('setSyncedToTicketsystem')->willReturnSelf();

        $this->jiraAuthenticationService->method('checkUserTicketSystem')->willReturn(true);
        $this->jiraTicketService->method('doesTicketExist')->willReturn(true);

        $response = new stdClass();
        $response->id = '99999';
        $this->jiraHttpClientService->method('post')->willReturn($response);

        $result = $this->service->syncWorkLog($user, $ticketSystem, $entry, []);

        self::assertArrayHasKey('worklogId', $result);
        self::assertSame(99999, $result['worklogId']);
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Mcp;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Enum\WorklogSyncStatus;
use App\Mcp\Tool\BulkLogTimeTool;
use App\Mcp\Tool\DeleteEntryTool;
use App\Mcp\Tool\GetDayTool;
use App\Mcp\Tool\GetSyncRunTool;
use App\Mcp\Tool\GetTicketInfoTool;
use App\Mcp\Tool\GetTimeBalanceTool;
use App\Mcp\Tool\ListActivitiesTool;
use App\Mcp\Tool\ListContractsTool;
use App\Mcp\Tool\ListCustomersTool;
use App\Mcp\Tool\ListPresetsTool;
use App\Mcp\Tool\ListProjectsTool;
use App\Mcp\Tool\ListRecentEntriesTool;
use App\Mcp\Tool\ListSyncConflictsTool;
use App\Mcp\Tool\ListTeamsTool;
use App\Mcp\Tool\ListTicketSystemsTool;
use App\Mcp\Tool\ListUsersTool;
use App\Mcp\Tool\LogTimeTool;
use App\Mcp\Tool\OnboardCustomerTool;
use App\Mcp\Tool\OnboardProjectTool;
use App\Mcp\Tool\OnboardUserTool;
use App\Mcp\Tool\ResolveSyncConflictTool;
use App\Mcp\Tool\SaveContractTool;
use App\Mcp\Tool\SaveTeamTool;
use App\Mcp\Tool\SaveTicketSystemTool;
use App\Mcp\Tool\SetCustomerActiveTool;
use App\Mcp\Tool\SetProjectActiveTool;
use App\Mcp\Tool\SetUserActiveTool;
use App\Mcp\Tool\SyncJiraWorklogsTool;
use App\Mcp\Tool\UpdateEntryTool;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Security\ApiToken\ApiAccessToken;
use App\Service\Sync\ConflictResolutionService;
use App\Service\Sync\VerifyWorklogsService;
use App\ValueObject\Sync\ResolutionResult;
use DateTimeImmutable;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use ReflectionClass;
use Tests\AbstractWebTestCase;
use Tests\Traits\ActsAsApiTokenUser;

use function array_column;
use function array_is_list;
use function array_keys;
use function basename;
use function count;
use function dirname;
use function glob;
use function is_string;
use function sort;
use function sprintf;

/**
 * Integration tests for the MCP tools (ADR-021 Phase 5), exercised through the
 * real container with a scoped ApiAccessToken — the same path the /mcp endpoint
 * takes. Fixture user 1 = 'unittest'; project/customer/activity id 1 exist;
 * project 1's jira_id is 'SA'.
 */
final class McpToolsTest extends AbstractWebTestCase
{
    use ActsAsApiTokenUser;

    public function testListActivitiesReturnsEntriesWithReadScope(): void
    {
        $this->useToken(['activities:read']);

        $result = self::getContainer()->get(ListActivitiesTool::class)->listActivities();

        self::assertNotEmpty($result['activities']);
        self::assertArrayHasKey('id', $result['activities'][0]);
        self::assertArrayHasKey('name', $result['activities'][0]);
        self::assertArrayHasKey('needs_ticket', $result['activities'][0]);
    }

    public function testListActivitiesIsDeniedWithoutScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(ListActivitiesTool::class)->listActivities();
    }

    public function testListActivitiesIsDeniedForSessionAuth(): void
    {
        // setUp() logs in a session; without an ApiAccessToken the tool refuses.
        $this->expectException(ToolCallException::class);
        self::getContainer()->get(ListActivitiesTool::class)->listActivities();
    }

    public function testListProjectsReturnsBookableProjects(): void
    {
        $this->useToken(['projects:read']);

        $result = self::getContainer()->get(ListProjectsTool::class)->listProjects();

        self::assertNotEmpty($result['projects']);
        self::assertContains(1, array_column($result['projects'], 'id'));
    }

    public function testListProjectsIsDeniedWithoutScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(ListProjectsTool::class)->listProjects();
    }

    public function testListRecentEntriesReturnsArray(): void
    {
        $this->useToken(['entries:read']);

        $result = self::getContainer()->get(ListRecentEntriesTool::class)->listRecentEntries(30);

        self::assertArrayHasKey('entries', $result);
        self::assertIsList($result['entries']);
    }

    public function testListRecentEntriesIsDeniedWithoutScope(): void
    {
        $this->useToken(['projects:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(ListRecentEntriesTool::class)->listRecentEntries();
    }

    public function testLogTimeCreatesEntry(): void
    {
        $this->useToken(['entries:write']);

        $result = self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
            ticket: 'SA-123',
            durationMinutes: 60,
            description: 'logged via MCP',
        );

        self::assertArrayHasKey('result', $result);
        self::assertIsArray($result['result']);
        self::assertArrayHasKey('id', $result['result']);
    }

    public function testLogTimeIsDeniedWithoutScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
            durationMinutes: 60,
        );
    }

    public function testLogTimeRejectsUnknownProject(): void
    {
        $this->useToken(['entries:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: 'no-such-project-xyz',
            activity: '1',
            durationMinutes: 60,
        );
    }

    public function testLogTimeRequiresDurationOrTimes(): void
    {
        $this->useToken(['entries:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
        );
    }

    public function testLogTimeRejectsDurationPastMidnight(): void
    {
        $this->useToken(['entries:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
            durationMinutes: 120,
            start: '23:00',
        );
    }

    public function testLogTimeRejectsMalformedStart(): void
    {
        $this->useToken(['entries:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
            durationMinutes: 60,
            start: 'not-a-time',
        );
    }

    public function testDeleteEntryRemovesOwnEntry(): void
    {
        $this->useToken(['entries:write']);
        $logTime = self::getContainer()->get(LogTimeTool::class);
        $created = $logTime->logTime(project: '1', activity: '1', ticket: 'SA-1', durationMinutes: 30);
        self::assertIsArray($created['result'] ?? null);
        $id = $created['result']['id'];
        self::assertIsInt($id);

        $result = self::getContainer()->get(DeleteEntryTool::class)->deleteEntry($id);

        self::assertSame(['success' => true], $result);
    }

    public function testDeleteEntryIsDeniedWithoutScope(): void
    {
        $this->useToken(['reporting:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(DeleteEntryTool::class)->deleteEntry(1);
    }

    public function testGetTimeBalanceReturnsPeriods(): void
    {
        $this->useToken(['reporting:read']);

        $result = self::getContainer()->get(GetTimeBalanceTool::class)->getTimeBalance();

        self::assertArrayHasKey('warnings', $result);
        self::assertIsList($result['warnings']);
        foreach (['today', 'week', 'month'] as $period) {
            self::assertArrayHasKey($period, $result);
            self::assertIsArray($result[$period]);
            foreach (['ist', 'soll_total', 'soll_so_far', 'diff', 'status'] as $key) {
                self::assertArrayHasKey($key, $result[$period]);
            }
        }
    }

    public function testGetTimeBalanceIsDeniedWithoutScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(GetTimeBalanceTool::class)->getTimeBalance();
    }

    public function testGetTicketInfoReturnsScopes(): void
    {
        // Create an entry to report on.
        $this->useToken(['entries:write']);
        $created = self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', ticket: 'SA-9', durationMinutes: 30);
        self::assertIsArray($created['result'] ?? null);
        $id = $created['result']['id'];
        self::assertIsInt($id);

        $this->useToken(['reporting:read']);
        $info = self::getContainer()->get(GetTicketInfoTool::class)->getTicketInfo($id);

        foreach (['customer', 'project', 'activity', 'ticket', 'estimate', 'warnings'] as $key) {
            self::assertArrayHasKey($key, $info);
        }
        self::assertIsArray($info['estimate']);
        self::assertArrayHasKey('status', $info['estimate']);
    }

    public function testGetTicketInfoRejectsUnknownEntry(): void
    {
        $this->useToken(['reporting:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(GetTicketInfoTool::class)->getTicketInfo(999999);
    }

    public function testGetTicketInfoRejectsAnotherUsersEntry(): void
    {
        // An entry owned by user 2, requested by user 1 — must read as "not
        // found" so cross-user scope totals are never disclosed (IDOR).
        $container = self::getContainer();
        $em = $container->get('doctrine')->getManager();
        $owner = $container->get(UserRepository::class)->find(2);
        self::assertInstanceOf(User::class, $owner);
        $project = $container->get(ProjectRepository::class)->find(1);
        self::assertNotNull($project);
        $customer = $project->getCustomer();
        self::assertInstanceOf(Customer::class, $customer);
        $activity = $container->get(ActivityRepository::class)->find(1);
        self::assertInstanceOf(Activity::class, $activity);

        $entry = new Entry();
        $entry->setUser($owner)
            ->setCustomer($customer)
            ->setProject($project)
            ->setActivity($activity)
            ->setTicket('SA-77')
            ->setDescription('owned by user 2')
            ->setDay('2026-07-06')
            ->setStart('09:00:00')
            ->setEnd('10:00:00')
            ->setDuration(60);
        $em->persist($entry);
        $em->flush();
        $foreignId = (int) $entry->getId();

        $this->useToken(['reporting:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(GetTicketInfoTool::class)->getTicketInfo($foreignId);
    }

    public function testGetTicketInfoIsDeniedWithoutScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(GetTicketInfoTool::class)->getTicketInfo(1);
    }

    public function testLogTimeReturnsTicketInfoDayAndBalance(): void
    {
        $this->useToken(['entries:write']);

        $result = self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', ticket: 'SA-8', durationMinutes: 45);

        self::assertArrayHasKey('ticket_info', $result);
        self::assertArrayHasKey('balance', $result);
        self::assertIsArray($result['balance']);
        self::assertArrayHasKey('today', $result['balance']);
        // The booked day so far — the created entry must be in it.
        self::assertArrayHasKey('day', $result);
        self::assertIsArray($result['day']);
        self::assertIsList($result['day']['entries']);
        self::assertGreaterThanOrEqual(45, $result['day']['total_minutes']);
    }

    public function testGetDayReturnsTheBookedDay(): void
    {
        $this->useToken(['entries:read', 'entries:write']);
        $created = self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', ticket: 'SA-7', durationMinutes: 30);
        self::assertIsArray($created['result'] ?? null);

        $day = self::getContainer()->get(GetDayTool::class)->getDay();

        self::assertArrayHasKey('date', $day);
        self::assertIsList($day['entries']);
        self::assertNotEmpty($day['entries']);
        self::assertGreaterThanOrEqual(30, $day['total_minutes']);
        self::assertSame(count($day['entries']), $day['count']);
    }

    public function testGetDayRejectsAnInvalidDate(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(GetDayTool::class)->getDay('2026-13-99');
    }

    public function testGetDayIsDeniedWithoutScope(): void
    {
        $this->useToken(['reporting:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(GetDayTool::class)->getDay();
    }

    /**
     * MCP structuredContent must be a JSON object at the top level — a bare
     * array is rejected by strict clients (#573). Calls every registered tool
     * and asserts an object-shaped (string-keyed) result; the reflection sweep
     * below forces any future tool to join this guard.
     */
    public function testEveryToolReturnsATopLevelJsonObject(): void
    {
        $this->useToken([
            'entries:read', 'entries:write', 'projects:read', 'projects:write', 'activities:read', 'reporting:read',
            'customers:read', 'customers:write', 'users:read', 'users:write', 'teams:read', 'teams:write',
            'presets:read', 'ticketsystems:read', 'ticketsystems:write', 'contracts:read', 'contracts:write',
            'sync:read', 'sync:write',
        ]);
        $container = self::getContainer();
        [$syncRun, $syncState] = $this->prepareSyncToolFixtures();

        $created = $container->get(LogTimeTool::class)->logTime(project: '1', activity: '1', ticket: 'SA-5', durationMinutes: 15);
        self::assertIsArray($created['result'] ?? null);
        $entryId = $created['result']['id'];
        self::assertIsInt($entryId);

        $results = [
            'delete_entry' => null, // called last — it removes the entry
            'bulk_log_time' => $container->get(BulkLogTimeTool::class)->bulkLogTime(preset: '1', startDate: '2026-07-06', endDate: '2026-07-06', useContract: false, skipWeekend: false, skipHolidays: false, startTime: '09:00', endTime: '10:00'),
            'get_day' => $container->get(GetDayTool::class)->getDay(),
            'get_sync_run' => $container->get(GetSyncRunTool::class)->getSyncRun((int) $syncRun->getId()),
            'get_ticket_info' => $container->get(GetTicketInfoTool::class)->getTicketInfo($entryId),
            'get_time_balance' => $container->get(GetTimeBalanceTool::class)->getTimeBalance(),
            'list_activities' => $container->get(ListActivitiesTool::class)->listActivities(),
            'list_contracts' => $container->get(ListContractsTool::class)->listContracts(),
            'list_customers' => $container->get(ListCustomersTool::class)->listCustomers(),
            'list_presets' => $container->get(ListPresetsTool::class)->listPresets(),
            'list_projects' => $container->get(ListProjectsTool::class)->listProjects(),
            'list_recent_entries' => $container->get(ListRecentEntriesTool::class)->listRecentEntries(),
            'list_sync_conflicts' => $container->get(ListSyncConflictsTool::class)->listSyncConflicts(),
            'list_teams' => $container->get(ListTeamsTool::class)->listTeams(),
            'list_ticketsystems' => $container->get(ListTicketSystemsTool::class)->listTicketSystems(),
            'list_users' => $container->get(ListUsersTool::class)->listUsers(),
            'log_time' => $created,
            'onboard_customer' => $container->get(OnboardCustomerTool::class)->onboardCustomer(name: 'Guard Customer', global: true),
            'onboard_project' => $container->get(OnboardProjectTool::class)->onboardProject(name: 'Guard Project', customer: '1'),
            'onboard_user' => $container->get(OnboardUserTool::class)->onboardUser(username: 'guard.user', abbr: 'GRD', teamIds: [1]),
            'resolve_sync_conflict' => $container->get(ResolveSyncConflictTool::class)->resolveSyncConflict((int) $syncState->getId(), 'local'),
            'save_contract' => $container->get(SaveContractTool::class)->saveContract(user: 'noContract', start: '2020-01-01', end: '2020-12-31'),
            'save_team' => $container->get(SaveTeamTool::class)->saveTeam(name: 'Guard-Team', leadUser: 'unittest'),
            'save_ticketsystem' => $container->get(SaveTicketSystemTool::class)->saveTicketSystem(name: 'Guard-TS', type: 'JIRA'),
            'set_customer_active' => $container->get(SetCustomerActiveTool::class)->setCustomerActive('1', true),
            'set_project_active' => $container->get(SetProjectActiveTool::class)->setProjectActive('1', true),
            'set_user_active' => $container->get(SetUserActiveTool::class)->setUserActive('developer', true),
            'sync_jira_worklogs' => $container->get(SyncJiraWorklogsTool::class)->syncJiraWorklogs(type: 'verify', ticketSystemId: 1),
            'update_entry' => $container->get(UpdateEntryTool::class)->updateEntry(entryId: $entryId, description: 'edited via guard'),
        ];
        $results['delete_entry'] = $container->get(DeleteEntryTool::class)->deleteEntry($entryId);

        foreach ($results as $tool => $result) {
            self::assertIsArray($result, sprintf('%s: expected an array result', $tool));
            self::assertNotSame([], $result, sprintf('%s: an empty result cannot prove object shape', $tool));
            self::assertFalse(array_is_list($result), sprintf('%s must return a top-level JSON object, never a bare array (#573)', $tool));
        }

        $covered = array_keys($results);
        sort($covered);
        self::assertSame($this->declaredToolNames(), $covered, 'every #[McpTool] must be covered by this object-shape guard — add new tools here');
    }

    /**
     * A persisted run + parked conflict state for the sync tools, with the
     * Jira-touching services mocked in the container (ADR-023 §6).
     *
     * @return array{SyncRun, WorklogSyncState}
     */
    private function prepareSyncToolFixtures(): array
    {
        $container = self::getContainer();
        $em = $container->get('doctrine')->getManager();

        $ticketSystem = $em->find(TicketSystem::class, 1);
        self::assertInstanceOf(TicketSystem::class, $ticketSystem);
        $unittest = $container->get(UserRepository::class)->find(1);
        self::assertInstanceOf(User::class, $unittest);
        $project = $container->get(ProjectRepository::class)->find(1);
        self::assertNotNull($project);

        $syncRun = new SyncRun()
            ->setType(SyncRunType::VERIFY)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setTicketSystem($ticketSystem)
            ->setTriggeredBy($unittest)
            ->setScope([])
            ->setCounters([])
            ->setStartedAt(new DateTimeImmutable('2026-07-08 09:00:00'));
        $em->persist($syncRun);

        $conflictEntry = new Entry()
            ->setUser($unittest)
            ->setProject($project)
            ->setTicket('SA-42')
            ->setDay('2026-07-06')
            ->setStart('11:00:00')
            ->setEnd('12:00:00');
        $conflictEntry->setDuration(60);
        $em->persist($conflictEntry);

        $syncState = new WorklogSyncState()
            ->setEntry($conflictEntry)
            ->setTicketSystem($ticketSystem)
            ->setStatus(WorklogSyncStatus::CONFLICT)
            ->setLastSyncedAt(new DateTimeImmutable('2026-07-01 08:00:00'));
        $em->persist($syncState);
        $em->flush();

        $verifyStub = self::createStub(VerifyWorklogsService::class);
        $verifyStub->method('verify')->willReturn(
            new SyncRun()
                ->setType(SyncRunType::VERIFY)
                ->setStatus(SyncRunStatus::COMPLETED)
                ->setTicketSystem($ticketSystem)
                ->setTriggeredBy($unittest)
                ->setScope([])
                ->setCounters([])
                ->setStartedAt(new DateTimeImmutable('2026-07-09 10:00:00')),
        );
        $container->set(VerifyWorklogsService::class, $verifyStub);

        $resolutionStub = self::createStub(ConflictResolutionService::class);
        $resolutionStub->method('resolve')->willReturn(new ResolutionResult(true, 'pushed_local'));
        $container->set(ConflictResolutionService::class, $resolutionStub);

        return [$syncRun, $syncState];
    }

    /**
     * All tool names declared via #[McpTool] under src/Mcp/Tool, sorted.
     *
     * @return list<string>
     */
    private function declaredToolNames(): array
    {
        $names = [];
        $files = glob(dirname(__DIR__, 2) . '/src/Mcp/Tool/*.php');
        self::assertNotFalse($files);
        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'App\\Mcp\\Tool\\' . basename($file, '.php');
            foreach (new ReflectionClass($class)->getMethods() as $method) {
                foreach ($method->getAttributes(McpTool::class) as $attribute) {
                    $name = $attribute->newInstance()->name;
                    if (is_string($name)) {
                        $names[] = $name;
                    }
                }
            }
        }

        sort($names);

        return $names;
    }
}

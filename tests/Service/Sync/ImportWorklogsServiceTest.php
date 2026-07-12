<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\DTO\Jira\JiraIssueKeySearchResult;
use App\DTO\Jira\JiraUserIdentity;
use App\DTO\Jira\JiraWorkLog;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Entity\WorklogSyncState;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Repository\CustomerRepository;
use App\Repository\EntryRepository;
use App\Repository\UserRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Sync\CustomerUpserter;
use App\Service\Sync\EntryWorklogProjector;
use App\Service\Sync\ImportWorklogsService;
use App\Service\Sync\JiraAuthorMapper;
use App\Service\Sync\ProjectImportProposalService;
use App\Service\Sync\RemoteWorklogNormalizer;
use App\Service\Sync\TicketProjectResolver;
use App\Service\Tracking\DayClassService;
use App\ValueObject\Sync\ProjectResolution;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ImportWorklogsService::class)]
#[AllowMockObjectsWithoutExpectations]
final class ImportWorklogsServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private EntryRepository&MockObject $entryRepository;
    private JiraOAuthApiService&MockObject $api;
    private TicketProjectResolver&MockObject $projectResolver;
    private JiraAuthorMapper&MockObject $authorMapper;
    private DayClassService&MockObject $dayClassService;
    private UserRepository&MockObject $userRepository;
    private CustomerRepository&MockObject $customerRepository;
    private ImportWorklogsService $service;
    private ?string $capturedJql = null;
    private User $triggeredBy;
    private User $author;
    private TicketSystem $ticketSystem;
    private Project $project;
    private Activity $activity;
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entryRepository = $this->createMock(EntryRepository::class);
        $this->api = $this->createMock(JiraOAuthApiService::class);
        $this->projectResolver = $this->createMock(TicketProjectResolver::class);
        $this->authorMapper = $this->createMock(JiraAuthorMapper::class);
        $this->dayClassService = $this->createMock(DayClassService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->customerRepository = $this->createMock(CustomerRepository::class);

        $apiFactory = $this->createMock(JiraOAuthApiFactory::class);
        $apiFactory->method('create')->willReturn($this->api);
        $this->api->method('getMyself')->willReturn(new JiraUserIdentity(accountId: 'po'));

        $this->activity = self::createStub(Activity::class);
        $this->activity->method('getId')->willReturn(1);
        $this->entityManager->method('find')->willReturnCallback(
            fn (string $class, mixed $id): ?object => Activity::class === $class && 1 === $id ? $this->activity : null,
        );
        $this->entityManager->method('persist')->willReturnCallback(
            function (object $object): void { $this->persisted[] = $object; },
        );

        $customer = self::createStub(Customer::class);
        $this->project = self::createStub(Project::class);
        $this->project->method('getCustomer')->willReturn($customer);
        $this->triggeredBy = self::createStub(User::class);
        $this->triggeredBy->method('getId')->willReturn(1);
        $this->author = self::createStub(User::class);
        $this->author->method('getId')->willReturn(7);
        $this->author->method('getUsername')->willReturn('jdoe');
        $this->ticketSystem = self::createStub(TicketSystem::class);

        $this->service = new ImportWorklogsService(
            $this->entityManager,
            $this->entryRepository,
            $apiFactory,
            new RemoteWorklogNormalizer(),
            new EntryWorklogProjector(),
            $this->projectResolver,
            $this->authorMapper,
            $this->dayClassService,
            $this->userRepository,
            // Real proposal service driven through the mocked Jira/Tempo boundary
            // ($this->api), so the test exercises the true derivation, not a stub.
            new ProjectImportProposalService($apiFactory),
            new CustomerUpserter($this->customerRepository),
            new MockClock('2026-07-09 12:00:00'),
        );
    }

    /**
     * A ticket system with the ADR-026 P3 opt-in flag flipped on (the default
     * $this->ticketSystem stub returns false, so existing tests keep parking).
     */
    private function ticketSystemWithAutoImport(): TicketSystem
    {
        $ticketSystem = self::createStub(TicketSystem::class);
        $ticketSystem->method('getAutoImportUnresolvedProjects')->willReturn(true);

        return $ticketSystem;
    }

    /**
     * Stub the Tempo account endpoint for a project id with one confident
     * customer, yielding a SOURCE_TEMPO proposal.
     */
    private function stubConfidentTempo(int $projectId, string $customerName, string $customerKey): void
    {
        $account = (object) [
            'id' => 1,
            'key' => 'ACC1',
            'name' => 'Account One',
            'customer' => (object) ['id' => 10, 'key' => $customerKey, 'name' => $customerName],
        ];
        $this->api->method('getFromTenant')->willReturnCallback(
            static fn (string $path): array => str_contains($path, '/account/project/' . $projectId) ? [$account] : [],
        );
    }

    /**
     * @param list<string>                     $issueKeys
     * @param array<string, list<JiraWorkLog>> $worklogsByIssue
     */
    private function stubRemote(array $issueKeys, array $worklogsByIssue): void
    {
        $this->api->method('searchIssueKeysWithWorklogs')->willReturn(new JiraIssueKeySearchResult($issueKeys, false));
        $this->api->method('getIssueWorklogs')->willReturnCallback(
            static fn (string $key): array => $worklogsByIssue[$key] ?? [],
        );
    }

    private function worklog(int $id = 5001): JiraWorkLog
    {
        return new JiraWorkLog(
            id: $id,
            comment: 'jira-side work',
            started: '2026-06-10T09:00:00.000+0200',
            timeSpentSeconds: 3600,
            updated: '2026-06-10T10:00:00.000+0200',
            authorAccountId: 'acc-jdoe',
            authorName: 'jdoe',
        );
    }

    /**
     * @param list<string> $targetUsernames
     */
    private function import(array $targetUsernames = [], bool $dryRun = false): SyncRun
    {
        return $this->service->import($this->triggeredBy, $this->ticketSystem, new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'), 1, $targetUsernames, $dryRun);
    }

    private function importWith(TicketSystem $ticketSystem, bool $dryRun = false): SyncRun
    {
        return $this->service->import($this->triggeredBy, $ticketSystem, new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'), 1, [], $dryRun);
    }

    public function testUnknownActivityFailsRun(): void
    {
        $syncRun = $this->service->import($this->triggeredBy, $this->ticketSystem, new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'), 99);

        self::assertSame(SyncRunStatus::FAILED, $syncRun->getStatus());
    }

    public function testImportsWorklogAsPreSyncedEntryWithSyncState(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);

        $syncRun = $this->import();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['created'] ?? 0);
        $entries = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Entry));
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame('TIM-1', $entry->getTicket());
        self::assertSame(5001, $entry->getWorklogId());
        self::assertTrue($entry->getSyncedToTicketsystem());
        self::assertSame(60, $entry->getDuration());
        $states = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof WorklogSyncState));
        self::assertCount(1, $states);
        self::assertSame('2026-06-10T10:00:00.000+0200', $states[0]->getBaseUpdatedAt());
    }

    public function testLongWorklogCommentTruncatedToColumnLimit(): void
    {
        $longComment = str_repeat('x', 400);
        $worklog = new JiraWorkLog(
            id: 5001,
            comment: $longComment,
            started: '2026-06-10T09:00:00.000+0200',
            timeSpentSeconds: 3600,
            updated: '2026-06-10T10:00:00.000+0200',
            authorAccountId: 'acc-jdoe',
            authorName: 'jdoe',
        );
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$worklog]]);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);

        $this->import();

        $entries = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Entry));
        self::assertCount(1, $entries);
        // varchar(255) column under strict SQL mode would otherwise close the EntityManager.
        self::assertSame(Entry::DESCRIPTION_MAX_LENGTH, mb_strlen($entries[0]->getDescription()));
    }

    public function testAlreadyLinkedWorklogIsSkipped(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(new Entry());

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['already_linked'] ?? 0);
        self::assertSame(0, $syncRun->getCounters()['created'] ?? 0);
    }

    public function testUnknownAuthorWithTargetFilterSkips(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn(null);

        $syncRun = $this->import(targetUsernames: ['someoneelse']);

        self::assertSame(1, $syncRun->getCounters()['skipped_author'] ?? 0);
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof Entry));
    }

    public function testUnknownAuthorWithoutFilterCreatesShadow(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn(null);
        $this->authorMapper->method('createShadow')->willReturn($this->author);

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['shadow_users_created'] ?? 0);
        self::assertSame(1, $syncRun->getCounters()['created'] ?? 0);
        $kinds = array_map(static fn ($item) => $item->getKind(), $syncRun->getItems()->toArray());
        self::assertContains(SyncItemKind::SHADOW_USER_CREATED, $kinds);
    }

    public function testUnresolvedProjectParks(): void
    {
        $this->stubRemote(['XXX-1'], ['XXX-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution(null, 'no project for prefix XXX on this ticket system'));

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['unresolved_project'] ?? 0);
        $items = $syncRun->getItems()->toArray();
        self::assertCount(1, $items);
        self::assertSame(SyncItemKind::UNRESOLVED_PROJECT, $items[0]->getKind());
    }

    public function testAutoImportFlagOffParksAndNeverDerives(): void
    {
        // The default $this->ticketSystem stub returns false for the P3 flag —
        // the derivation boundary must not be touched at all.
        $this->api->expects(self::never())->method('getProjectInfo');
        $this->stubRemote(['SRVMO-1'], ['SRVMO-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution(null, 'no project for prefix SRVMO on this ticket system'));

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['unresolved_project'] ?? 0);
        self::assertSame(0, $syncRun->getCounters()['auto_imported_project'] ?? 0);
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof Project));
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof Customer));
    }

    public function testAutoImportFlagOnConfidentCreatesProjectAndCustomer(): void
    {
        $ticketSystem = $this->ticketSystemWithAutoImport();
        $this->stubRemote(['SRVMO-1'], ['SRVMO-1' => [$this->worklog()]]);
        $this->api->method('getProjectInfo')->willReturn(['id' => 20350, 'name' => 'Server Monitoring', 'categoryName' => 'NR: IT']);
        $this->stubConfidentTempo(20350, 'Netresearch', 'NR');
        $this->customerRepository->method('findOneByTempoCustomerKey')->willReturn(null);
        $this->customerRepository->method('findOneByName')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution(null, 'no project for prefix SRVMO on this ticket system'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);

        $syncRun = $this->importWith($ticketSystem);

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['auto_imported_project'] ?? 0);
        self::assertSame(0, $syncRun->getCounters()['unresolved_project'] ?? 0);
        self::assertSame(1, $syncRun->getCounters()['created'] ?? 0);

        $projects = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Project));
        self::assertCount(1, $projects);
        self::assertSame('SRVMO', $projects[0]->getJiraId());
        self::assertSame('Server Monitoring', $projects[0]->getName());

        $customers = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Customer));
        self::assertCount(1, $customers);
        self::assertSame('Netresearch', $customers[0]->getName());
        self::assertSame('NR', $customers[0]->getTempoCustomerKey());
        self::assertSame($customers[0], $projects[0]->getCustomer());

        // The worklog imported onto the auto-created project.
        $entries = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Entry));
        self::assertCount(1, $entries);
        self::assertSame('SRVMO-1', $entries[0]->getTicket());
        self::assertSame($projects[0], $entries[0]->getProject());

        $kinds = array_map(static fn ($item) => $item->getKind(), $syncRun->getItems()->toArray());
        self::assertContains(SyncItemKind::PROJECT_AUTO_IMPORTED, $kinds);
    }

    public function testAutoImportFlagOnAmbiguousParks(): void
    {
        $ticketSystem = $this->ticketSystemWithAutoImport();
        $this->stubRemote(['NRFE-1'], ['NRFE-1' => [$this->worklog()]]);
        $this->api->method('getProjectInfo')->willReturn(['id' => 10212, 'name' => 'NR Frontend', 'categoryName' => 'NR: IT']);
        // Two distinct customers, no single default link -> ambiguous -> park.
        $accounts = [
            (object) ['id' => 1, 'key' => 'A1', 'name' => 'Acc 1', 'customer' => (object) ['id' => 10, 'key' => 'NR', 'name' => 'Netresearch']],
            (object) ['id' => 2, 'key' => 'A2', 'name' => 'Acc 2', 'customer' => (object) ['id' => 20, 'key' => 'NRSO', 'name' => 'Netresearch Solutions']],
        ];
        $this->api->method('getFromTenant')->willReturnCallback(
            static fn (string $path): array => str_contains($path, '/account/project/10212') ? $accounts : [],
        );
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution(null, 'no project for prefix NRFE on this ticket system'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);

        $syncRun = $this->importWith($ticketSystem);

        self::assertSame(1, $syncRun->getCounters()['unresolved_project'] ?? 0);
        self::assertSame(0, $syncRun->getCounters()['auto_imported_project'] ?? 0);
        self::assertSame(0, $syncRun->getCounters()['created'] ?? 0);
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof Project));
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof Customer));
    }

    public function testAutoImportFlagOnNoneParks(): void
    {
        $ticketSystem = $this->ticketSystemWithAutoImport();
        $this->stubRemote(['SRVMO-1'], ['SRVMO-1' => [$this->worklog()]]);
        // A real project, but no Tempo customer and no category -> SOURCE_NONE -> park.
        $this->api->method('getProjectInfo')->willReturn(['id' => 20350, 'name' => 'Server Monitoring', 'categoryName' => null]);
        $this->api->method('getFromTenant')->willReturn([]);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution(null, 'no project for prefix SRVMO on this ticket system'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);

        $syncRun = $this->importWith($ticketSystem);

        self::assertSame(1, $syncRun->getCounters()['unresolved_project'] ?? 0);
        self::assertSame(0, $syncRun->getCounters()['auto_imported_project'] ?? 0);
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof Project));
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof Customer));
    }

    public function testAutoImportDerivesPrefixOncePerRun(): void
    {
        $ticketSystem = $this->ticketSystemWithAutoImport();
        // Two issues sharing the SRVMO prefix — the prefix must be derived once.
        $this->stubRemote(
            ['SRVMO-1', 'SRVMO-2'],
            ['SRVMO-1' => [$this->worklog(5001)], 'SRVMO-2' => [$this->worklog(5002)]],
        );
        $this->api->expects(self::once())->method('getProjectInfo')->willReturn(['id' => 20350, 'name' => 'Server Monitoring', 'categoryName' => null]);
        $this->stubConfidentTempo(20350, 'Netresearch', 'NR');
        $this->customerRepository->method('findOneByTempoCustomerKey')->willReturn(null);
        $this->customerRepository->method('findOneByName')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution(null, 'no project for prefix SRVMO on this ticket system'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);

        $syncRun = $this->importWith($ticketSystem);

        // Derived once, one project + customer, but both worklogs imported onto it.
        self::assertSame(1, $syncRun->getCounters()['auto_imported_project'] ?? 0);
        self::assertSame(2, $syncRun->getCounters()['created'] ?? 0);
        self::assertCount(1, array_filter($this->persisted, static fn (object $o): bool => $o instanceof Project));
        self::assertCount(1, array_filter($this->persisted, static fn (object $o): bool => $o instanceof Customer));
    }

    public function testProbableDuplicateParks(): void
    {
        $duplicate = self::createStub(Entry::class);
        $duplicate->method('getId')->willReturn(77);
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn($duplicate);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['probable_duplicate'] ?? 0);
        self::assertSame(SyncItemKind::PROBABLE_DUPLICATE, $syncRun->getItems()->toArray()[0]->getKind());
        self::assertSame(0, $syncRun->getCounters()['created'] ?? 0);
    }

    public function testMidnightCrossingWorklogParksAsError(): void
    {
        $late = new JiraWorkLog(id: 5002, comment: 'late', started: '2026-06-10T23:30:00.000+0000', timeSpentSeconds: 7200, authorAccountId: 'acc-jdoe');
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$late]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['errors'] ?? 0);
        self::assertSame(0, $syncRun->getCounters()['created'] ?? 0);
    }

    public function testDryRunCountsWithoutPersisting(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);

        $syncRun = $this->import(dryRun: true);

        self::assertSame(1, $syncRun->getCounters()['would_create'] ?? 0);
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof Entry));
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof WorklogSyncState));
    }

    public function testDayClassesRecalculatedPerAffectedUserDay(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog(5001), $this->worklog(5003)]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);

        $this->dayClassService->expects(self::once())->method('recalculate')->with(7, '2026-06-10');

        $this->import();
    }

    public function testIssueFetchFailureContinues(): void
    {
        $this->api->method('searchIssueKeysWithWorklogs')->willReturn(new JiraIssueKeySearchResult(['BAD-1', 'TIM-1'], false));
        $this->api->method('getIssueWorklogs')->willReturnCallback(
            fn (string $key): array => 'BAD-1' === $key ? throw new RuntimeException('gone') : [$this->worklog()],
        );
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);

        $syncRun = $this->import();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['errors'] ?? 0);
        self::assertSame(1, $syncRun->getCounters()['created'] ?? 0);
    }

    public function testZeroDurationWorklogIsSkipped(): void
    {
        $zero = new JiraWorkLog(id: 5009, comment: 'zero', started: '2026-06-10T09:00:00.000+0200', timeSpentSeconds: 0, authorAccountId: 'acc-jdoe');
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$zero]]);

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['skipped_zero_duration'] ?? 0);
        self::assertSame(0, $syncRun->getCounters()['created'] ?? 0);
    }

    public function testDayClassRecalcUsesShadowUserIdAssignedAtFlush(): void
    {
        $shadow = new User()->setUsername('ghost')->setActive(false);
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-ghost');
        $this->authorMapper->method('find')->willReturn(null);
        $this->authorMapper->method('createShadow')->willReturn($shadow);
        // Simulate Doctrine assigning the AUTO id at flush time — before the fix,
        // recalculate() received 0 because getId() was still null when the key was built.
        $this->entityManager->method('flush')->willReturnCallback(static function () use ($shadow): void {
            new ReflectionProperty(User::class, 'id')->setValue($shadow, 55);
        });
        $this->dayClassService->expects(self::once())->method('recalculate')->with(55, '2026-06-10');

        $this->import();
    }

    public function testNonEmptyTargetScopesSearchJqlByAuthor(): void
    {
        $this->captureSearchJql();
        $target = new User()->setUsername('jdoe');
        $userTicketsystem = new UserTicketsystem();
        $userTicketsystem->setTicketSystem($this->ticketSystem)->setRemoteAccountId('acc-jdoe');
        $target->getUserTicketsystems()->add($userTicketsystem);
        $this->userRepository->method('findBy')->willReturn([$target]);

        $this->import(targetUsernames: ['jdoe']);

        self::assertIsString($this->capturedJql);
        self::assertStringContainsString('worklogAuthor IN ("acc-jdoe")', $this->capturedJql);
        self::assertStringContainsString('worklogDate >= "2026-06-01"', $this->capturedJql);
    }

    public function testEmptyTargetLeavesSearchJqlAuthorUnrestricted(): void
    {
        $this->captureSearchJql();

        $this->import();

        self::assertIsString($this->capturedJql);
        self::assertStringNotContainsString('worklogAuthor', $this->capturedJql);
        self::assertStringContainsString('worklogDate >= "2026-06-01"', $this->capturedJql);
    }

    private function captureSearchJql(): void
    {
        $this->api->method('searchIssueKeysWithWorklogs')->willReturnCallback(
            function (string $jql): JiraIssueKeySearchResult {
                $this->capturedJql = $jql;

                return new JiraIssueKeySearchResult([], false);
            },
        );
    }
}

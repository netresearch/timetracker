<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\TicketSystemType;
use App\Repository\EntryRepository;
use App\Repository\UserRepository;
use App\Service\ExportService;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService as JiraOAuthApi;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Routing\RouterInterface;

use function array_key_exists;

/**
 * Unit tests for ExportService.
 *
 * @internal
 */
#[CoversClass(ExportService::class)]
final class ExportServiceTest extends TestCase
{
    // ==================== Helper Methods ====================

    /**
     * Create a mock ManagerRegistry with configurable repositories.
     *
     * @param Entry[]   $entries          Entries to return from findByDate
     * @param Entry[]   $filteredEntries  Entries to return from getFilteredEntries
     * @param Entry[][] $paginatedBatches Batches to return from findByDatePaginated
     * @param User|null $user             User to return from UserRepository->find()
     */
    private function createManagerRegistry(
        array $entries = [],
        array $filteredEntries = [],
        array $paginatedBatches = [],
        ?User $user = null,
    ): ManagerRegistry {
        $entryRepo = $this->createMock(EntryRepository::class);
        $entryRepo->method('findByDate')->willReturn($entries);
        $entryRepo->method('getFilteredEntries')->willReturn($filteredEntries);

        // Handle paginated batches
        if ([] !== $paginatedBatches) {
            $callIndex = 0;
            $entryRepo->method('findByDatePaginated')->willReturnCallback(
                static function () use (&$callIndex, $paginatedBatches): array {
                    $batch = $paginatedBatches[$callIndex] ?? [];
                    ++$callIndex;

                    return $batch;
                },
            );
        } else {
            $entryRepo->method('findByDatePaginated')->willReturn([]);
        }

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('find')->willReturn($user);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getRepository')->willReturnCallback(
            static fn (string $class) => match ($class) {
                Entry::class => $entryRepo,
                User::class => $userRepo,
                default => $entryRepo,
            },
        );

        return $doctrine;
    }

    /**
     * Create a mock JiraOAuthApiFactory.
     *
     * @param array<int, string>                $searchTickets        Ticket keys to return in search
     * @param array<string, array<int, string>> $jiraLabelsByIssue    Labels by issue key
     * @param array<string, string>             $jiraSummariesByIssue Summaries by issue key
     * @param bool                              $throwException       Whether to throw exception
     */
    private function createJiraFactory(
        array $searchTickets = [],
        array $jiraLabelsByIssue = [],
        array $jiraSummariesByIssue = [],
        bool $throwException = false,
    ): JiraOAuthApiFactory {
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/oauth-callback');

        $doctrine = $this->createManagerRegistry();
        $user = new User();
        $ticketSystem = new TicketSystem();

        // Create a stub JiraOAuthApi
        $jiraApi = new class($user, $ticketSystem, $doctrine, $router, $searchTickets, $jiraLabelsByIssue, $jiraSummariesByIssue, $throwException) extends JiraOAuthApi {
            public function __construct(
                User $user,
                TicketSystem $ticketSystem,
                ManagerRegistry $managerRegistry,
                RouterInterface $router,
                /** @var array<int, string> */
                private readonly array $keys,
                /** @var array<string, array<int, string>> */
                private array $labels,
                /** @var array<string, string> */
                private array $summaries,
                private bool $shouldThrow,
            ) {
                parent::__construct($user, $ticketSystem, $managerRegistry, $router);
            }

            /** @param array<int, string> $fields */
            public function searchTicket(string $jql, array $fields, int $limit = 1): object
            {
                if ($this->shouldThrow) {
                    throw new Exception('API Error');
                }

                $issues = [];
                foreach ($this->keys as $key) {
                    $fieldsObj = (object) [];
                    if (array_key_exists($key, $this->labels)) {
                        $fieldsObj->labels = $this->labels[$key];
                    }
                    if (array_key_exists($key, $this->summaries)) {
                        $fieldsObj->summary = $this->summaries[$key];
                    }
                    $issues[] = (object) ['key' => $key, 'fields' => $fieldsObj];
                }

                return (object) ['issues' => $issues];
            }
        };

        $factory = $this->createMock(JiraOAuthApiFactory::class);
        $factory->method('create')->willReturn($jiraApi);

        return $factory;
    }

    /**
     * Create an Entry with all related entities.
     */
    private function createEntry(
        ?User $user = null,
        ?Customer $customer = null,
        ?Project $project = null,
        ?Activity $activity = null,
        string $ticket = '',
        ?int $worklogId = null,
        ?string $description = null,
    ): Entry {
        $entry = new Entry();
        $entry->setStart(new DateTime('2025-01-15 09:00:00'));
        $entry->setEnd(new DateTime('2025-01-15 17:00:00'));
        $entry->setDay(new DateTime('2025-01-15'));

        if (null !== $user) {
            $entry->setUser($user);
        }
        if (null !== $customer) {
            $entry->setCustomer($customer);
        }
        if (null !== $project) {
            $entry->setProject($project);
        }
        if (null !== $activity) {
            $entry->setActivity($activity);
        }
        if ('' !== $ticket) {
            $entry->setTicket($ticket);
        }
        if (null !== $worklogId) {
            $entry->setWorklogId($worklogId);
        }
        if (null !== $description) {
            $entry->setDescription($description);
        }

        return $entry;
    }

    // ==================== exportEntries tests ====================

    public function testExportEntriesReturnsRepositoryResults(): void
    {
        $entries = [new Entry(), new Entry()];
        $doctrine = $this->createManagerRegistry(entries: $entries);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->exportEntries(1, 2025, 8, null, null, null);

        self::assertSame($entries, $result);
    }

    public function testExportEntriesWithProjectAndCustomerFilters(): void
    {
        $entries = [new Entry()];
        $doctrine = $this->createManagerRegistry(entries: $entries);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->exportEntries(1, 2025, 1, 42, 99, ['day' => 'ASC']);

        self::assertSame($entries, $result);
    }

    // ==================== exportEntriesBatched tests ====================

    public function testExportEntriesBatchedYieldsBatches(): void
    {
        $batch1 = [new Entry(), new Entry()];
        $batch2 = [new Entry()];
        $doctrine = $this->createManagerRegistry(paginatedBatches: [$batch1, $batch2, []]);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $generator = $service->exportEntriesBatched(1, 2025, 1, null, null, null, 2);

        $batches = iterator_to_array($generator);
        self::assertCount(2, $batches);
        self::assertSame($batch1, $batches[0]);
        self::assertSame($batch2, $batches[1]);
    }

    public function testExportEntriesBatchedWithNoEntries(): void
    {
        $doctrine = $this->createManagerRegistry(paginatedBatches: [[]]);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $generator = $service->exportEntriesBatched(1, 2025, 1);

        $batches = iterator_to_array($generator);
        self::assertCount(0, $batches);
    }

    public function testExportEntriesBatchedStopsWhenBatchSmallerThanLimit(): void
    {
        // First batch has 3 entries (full), second has 1 (incomplete), should stop
        $batch1 = [new Entry(), new Entry(), new Entry()];
        $batch2 = [new Entry()];
        $doctrine = $this->createManagerRegistry(paginatedBatches: [$batch1, $batch2]);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $generator = $service->exportEntriesBatched(1, 2025, 1, null, null, null, 3);

        $batches = iterator_to_array($generator);
        self::assertCount(2, $batches);
    }

    // ==================== getEntries tests ====================

    public function testGetEntriesReturnsFormattedData(): void
    {
        $user = (new User())->setUsername('johndoe');
        $customer = (new Customer())->setName('Acme Corp');
        $project = (new Project())->setName('Project X');
        $activity = (new Activity())->setName('Development');

        $entry = $this->createEntry($user, $customer, $project, $activity, '', null, 'Test description');

        // Set entity ID via reflection
        $reflection = new ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($entry, 123);

        $doctrine = $this->createManagerRegistry(filteredEntries: [$entry]);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        self::assertCount(1, $result);
        self::assertSame(123, $result[0]['id']);
        self::assertSame('johndoe', $result[0]['user']);
        self::assertSame('Acme Corp', $result[0]['customer']);
        self::assertSame('Project X', $result[0]['project']);
        self::assertSame('Development', $result[0]['activity']);
        self::assertSame('Test description', $result[0]['description']);
        self::assertSame('2025-01-15 09:00:00', $result[0]['start']);
        self::assertSame('2025-01-15 17:00:00', $result[0]['end']);
    }

    public function testGetEntriesWithDateFilters(): void
    {
        $user = new User();
        $doctrine = $this->createManagerRegistry(filteredEntries: []);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user, null, '2025-01-01', '2025-01-31');

        self::assertSame([], $result);
    }

    public function testGetEntriesWithProjectAndUserFilters(): void
    {
        $user = new User();
        $doctrine = $this->createManagerRegistry(filteredEntries: []);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user, null, '', '', [1, 2], [10, 20]);

        self::assertSame([], $result);
    }

    public function testGetEntriesWithEmptyRelatedEntities(): void
    {
        $entry = $this->createEntry();

        $reflection = new ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($entry, 1);

        $doctrine = $this->createManagerRegistry(filteredEntries: [$entry]);
        $jiraFactory = $this->createJiraFactory();

        $user = new User();
        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        self::assertCount(1, $result);
        self::assertSame('', $result[0]['user']);
        self::assertSame('', $result[0]['customer']);
        self::assertSame('', $result[0]['project']);
        self::assertSame('', $result[0]['activity']);
    }

    public function testGetEntriesWithTicketUrl(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(true)
            ->setType(TicketSystemType::JIRA)
            ->setTicketUrl('https://jira.example.com/browse/%s');

        $project = (new Project())
            ->setName('JIRA Project')
            ->setTicketSystem($ticketSystem);

        $entry = $this->createEntry(null, null, $project, null, 'PROJ-123');

        $reflection = new ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($entry, 1);

        $doctrine = $this->createManagerRegistry(filteredEntries: [$entry]);
        $jiraFactory = $this->createJiraFactory();

        $user = new User();
        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        self::assertSame('PROJ-123', $result[0]['ticket']);
        self::assertSame('https://jira.example.com/browse/PROJ-123', $result[0]['ticket_url']);
    }

    public function testGetEntriesWithWorklogUrl(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(true)
            ->setType(TicketSystemType::JIRA)
            ->setTicketUrl('https://jira.example.com/browse/%s');

        $project = (new Project())
            ->setName('JIRA Project')
            ->setTicketSystem($ticketSystem);

        $entry = $this->createEntry(null, null, $project, null, 'PROJ-456', 99999);

        $reflection = new ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($entry, 1);

        $doctrine = $this->createManagerRegistry(filteredEntries: [$entry]);
        $jiraFactory = $this->createJiraFactory();

        $user = new User();
        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        $worklogUrl = $result[0]['worklog_url'];
        self::assertIsString($worklogUrl);
        self::assertStringContainsString('PROJ-456', $worklogUrl);
        self::assertStringContainsString('99999', $worklogUrl);
    }

    public function testGetEntriesTicketUrlEmptyWithoutTicket(): void
    {
        $entry = $this->createEntry();

        $reflection = new ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($entry, 1);

        $doctrine = $this->createManagerRegistry(filteredEntries: [$entry]);
        $jiraFactory = $this->createJiraFactory();

        $user = new User();
        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        self::assertSame('', $result[0]['ticket_url']);
        self::assertSame('', $result[0]['worklog_url']);
    }

    public function testGetEntriesTicketUrlWithoutProject(): void
    {
        $entry = $this->createEntry();
        $entry->setTicket('ORPHAN-123');

        $reflection = new ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($entry, 1);

        $doctrine = $this->createManagerRegistry(filteredEntries: [$entry]);
        $jiraFactory = $this->createJiraFactory();

        $user = new User();
        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        self::assertSame('', $result[0]['ticket_url']);
    }

    public function testGetEntriesTicketUrlWithoutTicketSystem(): void
    {
        $project = (new Project())->setName('No Ticket System');
        $entry = $this->createEntry(null, null, $project, null, 'TEST-1');

        $reflection = new ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($entry, 1);

        $doctrine = $this->createManagerRegistry(filteredEntries: [$entry]);
        $jiraFactory = $this->createJiraFactory();

        $user = new User();
        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        self::assertSame('', $result[0]['ticket_url']);
    }

    public function testGetEntriesWorklogUrlEmptyWithoutWorklogId(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(true)
            ->setType(TicketSystemType::JIRA)
            ->setTicketUrl('https://jira.example.com/browse/%s');

        $project = (new Project())
            ->setName('JIRA Project')
            ->setTicketSystem($ticketSystem);

        $entry = $this->createEntry(null, null, $project, null, 'PROJ-789');
        // No worklog ID set

        $reflection = new ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($entry, 1);

        $doctrine = $this->createManagerRegistry(filteredEntries: [$entry]);
        $jiraFactory = $this->createJiraFactory();

        $user = new User();
        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        self::assertSame('', $result[0]['worklog_url']);
    }

    public function testGetEntriesWorklogUrlEmptyForNonJiraTicketSystem(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(true)
            ->setType(TicketSystemType::OTRS)
            ->setTicketUrl('https://otrs.example.com/ticket/%s');

        $project = (new Project())
            ->setName('OTRS Project')
            ->setTicketSystem($ticketSystem);

        $entry = $this->createEntry(null, null, $project, null, '123456', 456);

        $reflection = new ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($entry, 1);

        $doctrine = $this->createManagerRegistry(filteredEntries: [$entry]);
        $jiraFactory = $this->createJiraFactory();

        $user = new User();
        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        self::assertSame('', $result[0]['worklog_url']);
    }

    public function testGetEntriesTicketUrlNonJiraNoBookTime(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(false)
            ->setType(TicketSystemType::OTRS)
            ->setTicketUrl('https://otrs.example.com/ticket/%s');

        $project = (new Project())
            ->setName('OTRS Project')
            ->setTicketSystem($ticketSystem);

        $entry = $this->createEntry(null, null, $project, null, '123456');

        $reflection = new ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($entry, 1);

        $doctrine = $this->createManagerRegistry(filteredEntries: [$entry]);
        $jiraFactory = $this->createJiraFactory();

        $user = new User();
        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        // Should still generate URL using ticket system URL template
        self::assertSame('https://otrs.example.com/ticket/123456', $result[0]['ticket_url']);
    }

    public function testGetEntriesHandlesEmptyFilteredEntries(): void
    {
        // Test when no entries are returned
        $doctrine = $this->createManagerRegistry(filteredEntries: []);
        $jiraFactory = $this->createJiraFactory();

        $user = new User();
        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        self::assertSame([], $result);
    }

    // ==================== enrichEntriesWithTicketInformation tests ====================

    public function testEnrichEntriesReturnsSameEntriesWhenSearchTicketsFalse(): void
    {
        $entries = [new Entry(), new Entry()];
        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->enrichEntriesWithTicketInformation(1, $entries, true, true, false);

        self::assertSame($entries, $result);
    }

    public function testEnrichEntriesReturnsSameEntriesWhenUserNotFound(): void
    {
        $entries = [new Entry()];
        $doctrine = $this->createManagerRegistry(user: null);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->enrichEntriesWithTicketInformation(999, $entries, true, true, true);

        self::assertSame($entries, $result);
    }

    public function testEnrichEntriesSkipsEntriesWithoutTicket(): void
    {
        $user = new User();
        $entry = new Entry();
        // No ticket set

        $doctrine = $this->createManagerRegistry(user: $user);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->enrichEntriesWithTicketInformation(1, [$entry], true, true, true);

        self::assertSame([$entry], $result);
        self::assertNull($entry->getBillable());
    }

    public function testEnrichEntriesSkipsEntriesWithoutProject(): void
    {
        $entry = (new Entry())->setTicket('PROJ-1');
        // No project set

        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->enrichEntriesWithTicketInformation(1, [$entry], true, true, true);

        self::assertSame([$entry], $result);
    }

    public function testEnrichEntriesSkipsEntriesWithoutTicketSystem(): void
    {
        $project = (new Project())->setName('No TS');
        $entry = (new Entry())->setTicket('PROJ-1')->setProject($project);

        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->enrichEntriesWithTicketInformation(1, [$entry], true, true, true);

        self::assertSame([$entry], $result);
    }

    public function testEnrichEntriesSkipsNonBookTimeTicketSystems(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(false)
            ->setType(TicketSystemType::JIRA);
        $project = (new Project())->setTicketSystem($ticketSystem);
        $entry = (new Entry())->setTicket('PROJ-1')->setProject($project);

        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->enrichEntriesWithTicketInformation(1, [$entry], true, true, true);

        self::assertSame([$entry], $result);
    }

    public function testEnrichEntriesSkipsNonJiraTicketSystems(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(true)
            ->setType(TicketSystemType::OTRS);
        $project = (new Project())->setTicketSystem($ticketSystem);
        $entry = (new Entry())->setTicket('123456')->setProject($project);

        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->enrichEntriesWithTicketInformation(1, [$entry], true, true, true);

        self::assertSame([$entry], $result);
    }

    public function testEnrichEntriesSetsBillableAndSummary(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(true)
            ->setType(TicketSystemType::JIRA);
        $project = (new Project())->setTicketSystem($ticketSystem);

        $entry1 = (new Entry())->setTicket('TT-123')->setProject($project);
        $entry2 = (new Entry())->setTicket('TT-999')->setProject($project);

        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory(
            searchTickets: ['TT-123', 'TT-999'],
            jiraLabelsByIssue: ['TT-123' => ['billable']],
            jiraSummariesByIssue: ['TT-123' => 'Summary 1'],
        );

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->enrichEntriesWithTicketInformation(1, [$entry1, $entry2], true, true, true);

        self::assertTrue($result[0]->getBillable());
        self::assertSame('Summary 1', $result[0]->getTicketTitle());
        self::assertNull($result[1]->getBillable());
        self::assertNull($result[1]->getTicketTitle());
    }

    public function testEnrichEntriesOnlyBillable(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(true)
            ->setType(TicketSystemType::JIRA);
        $project = (new Project())->setTicketSystem($ticketSystem);

        $entry = (new Entry())->setTicket('TT-100')->setProject($project);

        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory(
            searchTickets: ['TT-100'],
            jiraLabelsByIssue: ['TT-100' => ['billable']],
        );

        $service = new ExportService($doctrine, $jiraFactory);
        // Only billable, not title
        $result = $service->enrichEntriesWithTicketInformation(1, [$entry], true, false, true);

        self::assertTrue($result[0]->getBillable());
        self::assertNull($result[0]->getTicketTitle());
    }

    public function testEnrichEntriesOnlySummary(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(true)
            ->setType(TicketSystemType::JIRA);
        $project = (new Project())->setTicketSystem($ticketSystem);

        $entry = (new Entry())->setTicket('TT-200')->setProject($project);

        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory(
            searchTickets: ['TT-200'],
            jiraSummariesByIssue: ['TT-200' => 'The Summary'],
        );

        $service = new ExportService($doctrine, $jiraFactory);
        // Only title, not billable
        $result = $service->enrichEntriesWithTicketInformation(1, [$entry], false, true, true);

        self::assertNull($result[0]->getBillable());
        self::assertSame('The Summary', $result[0]->getTicketTitle());
    }

    public function testEnrichEntriesSkipsWhenNoFieldsRequested(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(true)
            ->setType(TicketSystemType::JIRA);
        $project = (new Project())->setTicketSystem($ticketSystem);

        $entry = (new Entry())->setTicket('TT-300')->setProject($project);

        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        // Neither billable nor title requested
        $result = $service->enrichEntriesWithTicketInformation(1, [$entry], false, false, true);

        self::assertSame([$entry], $result);
    }

    public function testEnrichEntriesContinuesOnApiException(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(true)
            ->setType(TicketSystemType::JIRA);
        $project = (new Project())->setTicketSystem($ticketSystem);

        $entry = (new Entry())->setTicket('TT-ERROR')->setProject($project);

        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory(throwException: true);

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->enrichEntriesWithTicketInformation(1, [$entry], true, true, true);

        // Should return entries unchanged when API fails
        self::assertSame([$entry], $result);
    }

    public function testEnrichEntriesHandlesEmptyEntries(): void
    {
        $entries = [];

        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->enrichEntriesWithTicketInformation(1, $entries, true, true, true);

        self::assertSame([], $result);
    }

    public function testEnrichEntriesHandlesNonBillableLabels(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(true)
            ->setType(TicketSystemType::JIRA);
        $project = (new Project())->setTicketSystem($ticketSystem);

        $entry = (new Entry())->setTicket('TT-400')->setProject($project);

        $doctrine = $this->createManagerRegistry(user: new User());
        $jiraFactory = $this->createJiraFactory(
            searchTickets: ['TT-400'],
            jiraLabelsByIssue: ['TT-400' => ['internal', 'maintenance']], // No 'billable' label
        );

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->enrichEntriesWithTicketInformation(1, [$entry], true, false, true);

        self::assertFalse($result[0]->getBillable());
    }

    // ==================== getUsername tests ====================

    public function testGetUsernameReturnsUsername(): void
    {
        $user = (new User())->setUsername('testuser');
        $doctrine = $this->createManagerRegistry(user: $user);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getUsername(1);

        self::assertSame('testuser', $result);
    }

    public function testGetUsernameReturnsNullForNonexistentUser(): void
    {
        $doctrine = $this->createManagerRegistry(user: null);
        $jiraFactory = $this->createJiraFactory();

        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getUsername(999);

        self::assertNull($result);
    }

    // ==================== Edge cases ====================

    public function testGetEntriesHandlesWorklogUrlWithoutBookTime(): void
    {
        $ticketSystem = (new TicketSystem())
            ->setBookTime(false)
            ->setType(TicketSystemType::JIRA)
            ->setTicketUrl('https://jira.example.com/browse/%s');

        $project = (new Project())->setTicketSystem($ticketSystem);
        $entry = $this->createEntry(null, null, $project, null, 'TEST-1', 12345);

        $reflection = new ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($entry, 1);

        $doctrine = $this->createManagerRegistry(filteredEntries: [$entry]);
        $jiraFactory = $this->createJiraFactory();

        $user = new User();
        $service = new ExportService($doctrine, $jiraFactory);
        $result = $service->getEntries($user);

        // Worklog URL should be empty since bookTime is false
        self::assertSame('', $result[0]['worklog_url']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Enum\TicketSystemType;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Service\ExportService;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService as JiraOAuthApi;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * @internal
 *
 * @coversNothing
 */
final class ExportServiceTest extends TestCase
{
    private function makeSubject(
        array $entries,
        array $searchTickets = [],
        array $jiraLabelsByIssue = [],
        array $jiraSummariesByIssue = [],
    ): ExportService {
        $repo = $this->createMock(EntryRepository::class);
        $repo->method('findByDate')->willReturn($entries);

        /** @var ManagerRegistry&\PHPUnit\Framework\MockObject\MockObject $doctrine */
        $doctrine = $this->createMock(ManagerRegistry::class);

        // Provide appropriate repositories based on requested class
        $currentUser = new User();
        $mock = $this->getMockBuilder(\Doctrine\Persistence\ObjectRepository::class)->getMock();
        $mock->method('find')->willReturn($currentUser);
        $doctrine->method('getRepository')->willReturnCallback(static function (string $class) use ($repo, $mock): \PHPUnit\Framework\MockObject\MockObject {
            if (Entry::class === $class) {
                return $repo;
            }

            if (User::class === $class) {
                return $mock;
            }

            return $repo;
        });
        $doctrine->method('getManager');

        /** @var RouterInterface&\PHPUnit\Framework\MockObject\MockObject $router */
        $router = $this->createMock(RouterInterface::class);

        // Create a lightweight stub that satisfies the JiraOAuthApi return type
        $dummyUser = $currentUser;
        $dummyTs = new TicketSystem();
        $router->method('generate')->willReturn('/oauth-callback');
        $jiraApi = new class($dummyUser, $dummyTs, $doctrine, $router, $searchTickets, $jiraLabelsByIssue, $jiraSummariesByIssue) extends JiraOAuthApi {
            public function __construct(
                User $user,
                TicketSystem $ticketSystem,
                ManagerRegistry $managerRegistry,
                RouterInterface $router,
                private readonly array $keys,
                private array $labels,
                private array $summaries,
            ) {
                parent::__construct($user, $ticketSystem, $managerRegistry, $router);
            }

            public function searchTicket(string $jql, array $fields, int $limit = 1): object
            {
                $issues = [];
                foreach ($this->keys as $key) {
                    $issue = (object) [
                        'key' => $key,
                        'fields' => (object) [
                            'labels' => $this->labels[$key] ?? [],
                            'summary' => $this->summaries[$key] ?? null,
                        ],
                    ];
                    $issues[] = $issue;
                }

                return (object) ['issues' => $issues];
            }
        };
        /** @var JiraOAuthApiFactory&\PHPUnit\Framework\MockObject\MockObject $jiraFactory */
        $jiraFactory = $this->createMock(JiraOAuthApiFactory::class);
        $jiraFactory->method('create')->willReturn($jiraApi);

        // ExportService signature changed to (ManagerRegistry, JiraOAuthApiFactory)
        return new ExportService($doctrine, $jiraFactory);
    }

    public function testExportEntriesReturnsRepositoryResults(): void
    {
        $entries = [new Entry(), new Entry()];
        $exportService = $this->makeSubject($entries);
        $result = $exportService->exportEntries(1, 2025, 8, null, null, null);
        self::assertSame($entries, $result);
    }

    public function testEnrichEntriesSetsBillableAndSummary(): void
    {
        $user = new User();
        $ticketSystem = new TicketSystem();
        $ticketSystem->setBookTime(true);
        $ticketSystem->setType(TicketSystemType::JIRA);

        $entry1 = (new Entry())
            ->setUser($user)
            ->setProject((new \App\Entity\Project())->setTicketSystem($ticketSystem))
            ->setTicket('TT-123')
        ;
        $entry2 = (new Entry())
            ->setUser($user)
            ->setProject((new \App\Entity\Project())->setTicketSystem($ticketSystem))
            ->setTicket('TT-999')
        ;

        $exportService = $this->makeSubject([$entry1, $entry2], ['TT-123', 'TT-999'], ['TT-123' => ['billable']], ['TT-123' => 'Summary 1']);

        $result = $exportService->enrichEntriesWithTicketInformation(1, [$entry1, $entry2], true, false, true);

        self::assertTrue($result[0]->getBillable());
        self::assertSame('Summary 1', $result[0]->getTicketTitle());
        self::assertNull($result[1]->getBillable());
        self::assertNull($result[1]->getTicketTitle());
    }
}

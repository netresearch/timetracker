<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Service\ExportService;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

class ExportServiceTest extends TestCase
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
        $doctrine->method('getRepository')->willReturn($repo);
        $doctrine->method('getManager');

        /** @var RouterInterface&\PHPUnit\Framework\MockObject\MockObject $router */
        $router = $this->createMock(RouterInterface::class);

        $jiraApi = new class($searchTickets, $jiraLabelsByIssue, $jiraSummariesByIssue) {
            public function __construct(private array $keys, private array $labels, private array $summaries) {}
            public function searchTicket(string $jql, array $fields, string $max): \stdClass {
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

        return new ExportService($doctrine, $router, $jiraFactory);
    }

    public function testExportEntriesReturnsRepositoryResults(): void
    {
        $entries = [new Entry(), new Entry()];
        $service = $this->makeSubject($entries);
        $result = $service->exportEntries(1, 2025, 8, null, null, null);
        $this->assertSame($entries, $result);
    }

    public function testEnrichEntriesSetsBillableAndSummary(): void
    {
        $user = new User();
        $ticketSystem = new TicketSystem();
        $ticketSystem->setBookTime(true);
        $ticketSystem->setType('JIRA');

        $entry1 = (new Entry())
            ->setUser($user)
            ->setProject((new \App\Entity\Project())->setTicketSystem($ticketSystem))
            ->setTicket('TT-123');
        $entry2 = (new Entry())
            ->setUser($user)
            ->setProject((new \App\Entity\Project())->setTicketSystem($ticketSystem))
            ->setTicket('TT-999');

        $service = $this->makeSubject([$entry1, $entry2], ['TT-123', 'TT-999'], ['TT-123' => ['billable']], ['TT-123' => 'Summary 1']);

        $result = $service->enrichEntriesWithTicketInformation(1, [$entry1, $entry2], true, false, true);

        $this->assertTrue($result[0]->getBillable());
        $this->assertSame('Summary 1', $result[0]->getTicketTitle());
        $this->assertNull($result[1]->getBillable());
        $this->assertNull($result[1]->getTicketTitle());
    }
}



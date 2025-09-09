<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Enum\TicketSystemType;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use Doctrine\Persistence\ManagerRegistry;
use Exception;

use function count;
use function in_array;
use function is_array;
use function is_object;
use function sprintf;

class ExportService
{
    public function __construct(private readonly ManagerRegistry $managerRegistry, private readonly JiraOAuthApiFactory $jiraOAuthApiFactory)
    {
    }

    /**
     * Returns entries filtered and ordered.
     *
     * @param array<string, string>|null $arSort
     * @param array<int, int>|null       $arProjects
     * @param array<int, int>|null       $arUsers
     *
     * @return list<array<string, int|string|null>>
     */
    public function getEntries(\App\Entity\User $currentUser, ?array $arSort = null, string $strStart = '', string $strEnd = '', ?array $arProjects = null, ?array $arUsers = null): array
    {
        /** @var \App\Repository\EntryRepository $entryRepo */
        $entryRepo = $this->managerRegistry->getRepository(Entry::class);

        $arFilter = [];

        if ('' !== $strStart) {
            $arFilter['start'] = $strStart;
        }

        if ('' !== $strEnd) {
            $arFilter['end'] = $strEnd;
        }

        if (is_array($arProjects) && [] !== $arProjects) {
            $arFilter['projects'] = $arProjects;
        }

        if (is_array($arUsers) && [] !== $arUsers) {
            $arFilter['users'] = $arUsers;
        }

        // Add user filter to the filters array
        $arFilter['user'] = $currentUser->getId();

        $arEntries = $entryRepo->getFilteredEntries(
            $arFilter,
            0, // offset
            0, // limit (0 = no limit)
        );

        $arApi = [];

        $arReturn = [];
        foreach ($arEntries as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }

            $arReturn[] = [
                'id' => $entry->getId(),
                'user' => $entry->getUser() ? $entry->getUser()->getUsername() : '',
                'customer' => $entry->getCustomer() ? $entry->getCustomer()->getName() : '',
                'project' => $entry->getProject() ? $entry->getProject()->getName() : '',
                'activity' => $entry->getActivity() ? $entry->getActivity()->getName() : '',
                'description' => $entry->getDescription(),
                'start' => $entry->getStart()->format('Y-m-d H:i:s'),
                'end' => $entry->getEnd()->format('Y-m-d H:i:s'),
                'ticket' => $entry->getTicket(),
                'ticket_url' => $this->getTicketUrl($entry, $arApi, $currentUser),
                'worklog_url' => $this->getWorklogUrl($entry, $arApi, $currentUser),
            ];
        }

        return $arReturn;
    }

    /**
     * @param array<int, mixed> $arApi
     */
    private function getTicketUrl(Entry $entry, array &$arApi, \App\Entity\User $currentUser): string
    {
        if ('' === $entry->getTicket()) {
            return '';
        }

        $project = $entry->getProject();
        if (!$project) {
            return '';
        }

        $ticketSystem = $project->getTicketSystem();
        if (!$ticketSystem) {
            return '';
        }

        if ($ticketSystem->getBookTime()
            && TicketSystemType::JIRA === $ticketSystem->getType()
        ) {
            if (!isset($arApi[$ticketSystem->getId()])) {
                $arApi[$ticketSystem->getId()] = $this->jiraOAuthApiFactory->create($currentUser, $ticketSystem);
            }

            if (isset($arApi[$ticketSystem->getId()])) {
                // Use the ticket system's URL template directly
                return sprintf($ticketSystem->getTicketUrl(), $entry->getTicket());
            }
        }

        return sprintf($ticketSystem->getTicketUrl(), $entry->getTicket());
    }

    /**
     * @param array<int, mixed> $arApi
     */
    private function getWorklogUrl(Entry $entry, array &$arApi, \App\Entity\User $currentUser): string
    {
        if ('' === $entry->getTicket() || !$entry->getWorklogId()) {
            return '';
        }

        $project = $entry->getProject();
        if (!$project) {
            return '';
        }

        $ticketSystem = $project->getTicketSystem();
        if (!$ticketSystem) {
            return '';
        }

        if (!$ticketSystem->getBookTime() || TicketSystemType::JIRA !== $ticketSystem->getType()) {
            return '';
        }

        if (!isset($arApi[$ticketSystem->getId()])) {
            $arApi[$ticketSystem->getId()] = $this->jiraOAuthApiFactory->create($currentUser, $ticketSystem);
        }

        $apiService = $arApi[$ticketSystem->getId()] ?? null;
        if (!$apiService) {
            return '';
        }

        // Build worklog URL manually since getWorklogUrl method doesn't exist
        $baseUrl = rtrim($ticketSystem->getTicketUrl(), '/');
        $baseUrl = str_replace('%s', $entry->getTicket(), $baseUrl);

        return $baseUrl . '/worklog/' . $entry->getWorklogId();
    }

    /**
     * Export entries for a specific user and month.
     *
     * @param array<string, string>|null $arSort Sorting configuration
     *
     * @return Entry[]
     */
    public function exportEntries(int $userId, int $year, int $month, ?int $projectId = null, ?int $customerId = null, ?array $arSort = null): array
    {
        /** @var \App\Repository\EntryRepository $entryRepo */
        $entryRepo = $this->managerRegistry->getRepository(Entry::class);

        return $entryRepo->findByDate($userId, $year, $month, $projectId, $customerId, $arSort);
    }

    /**
     * Export entries in batches for memory efficiency.
     * 
     * @param array<string, string>|null $arSort Sorting configuration
     * @return \Generator<Entry[]>
     */
    public function exportEntriesBatched(int $userId, int $year, int $month, ?int $projectId = null, ?int $customerId = null, ?array $arSort = null, int $batchSize = 1000): \Generator
    {
        /** @var \App\Repository\EntryRepository $entryRepo */
        $entryRepo = $this->managerRegistry->getRepository(Entry::class);
        
        $offset = 0;
        do {
            $batch = $entryRepo->findByDatePaginated($userId, $year, $month, $projectId, $customerId, $arSort, $offset, $batchSize);
            if (!empty($batch)) {
                yield $batch;
            }
            $offset += $batchSize;
        } while (count($batch) === $batchSize);
    }

    /**
     * Enrich entries with ticket information from JIRA.
     *
     * @param Entry[] $entries
     *
     * @return Entry[]
     */
    public function enrichEntriesWithTicketInformation(int $userId, array $entries, bool $includeBillable = false, bool $includeTicketTitle = false, bool $searchTickets = false): array
    {
        if (!$searchTickets) {
            return $entries;
        }

        // Group entries by ticket system to minimize API calls
        $entriesByTicketSystem = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof Entry || !$entry->getTicket() || !$entry->getProject()) {
                continue;
            }

            $ticketSystem = $entry->getProject()->getTicketSystem();
            if (!$ticketSystem || !$ticketSystem->getBookTime() || TicketSystemType::JIRA !== $ticketSystem->getType()) {
                continue;
            }

            $ticketSystemId = $ticketSystem->getId();
            if (!isset($entriesByTicketSystem[$ticketSystemId])) {
                $entriesByTicketSystem[$ticketSystemId] = [
                    'ticketSystem' => $ticketSystem,
                    'entries' => [],
                    'tickets' => [],
                ];
            }

            $entriesByTicketSystem[$ticketSystemId]['entries'][] = $entry;
            $entriesByTicketSystem[$ticketSystemId]['tickets'][] = $entry->getTicket();
        }

        // Get user for API calls
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->managerRegistry->getRepository(\App\Entity\User::class);
        $user = $userRepo->find($userId);
        if (!$user) {
            return $entries;
        }

        // Fetch ticket information from JIRA for each ticket system
        foreach ($entriesByTicketSystem as $ticketSystemData) {
            $ticketSystem = $ticketSystemData['ticketSystem'];
            $tickets = array_unique($ticketSystemData['tickets']);

            $jiraApi = $this->jiraOAuthApiFactory->create($user, $ticketSystem);

            // Build JQL query for all tickets
            $ticketKeys = implode(',', $tickets);
            $jql = "key in ({$ticketKeys})";

            $fields = [];
            if ($includeBillable) {
                $fields[] = 'labels';
            }
            if ($includeTicketTitle) {
                $fields[] = 'summary';
            }

            if (empty($fields)) {
                continue;
            }

            try {
                $result = $jiraApi->searchTicket($jql, $fields, count($tickets));
                $ticketData = [];

                if (is_object($result) && property_exists($result, 'issues')) {
                    foreach ($result->issues as $issue) {
                        $ticketData[$issue->key] = $issue->fields;
                    }
                }

                // Update entries with ticket information
                foreach ($ticketSystemData['entries'] as $entry) {
                    $ticket = $entry->getTicket();
                    if (!isset($ticketData[$ticket])) {
                        continue;
                    }

                    $fields = $ticketData[$ticket];

                    if ($includeBillable && isset($fields->labels)) {
                        $isBillable = in_array('billable', $fields->labels, true);
                        $entry->setBillable($isBillable);
                    }

                    if ($includeTicketTitle && isset($fields->summary)) {
                        $entry->setTicketTitle($fields->summary);
                    }
                }
            } catch (Exception $e) {
                // Log error but continue with other entries
                // In a real implementation, you might want to use a logger here
                continue;
            }
        }

        return $entries;
    }

    /**
     * Get username by user ID for filename generation.
     */
    public function getUsername(int $userId): ?string
    {
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->managerRegistry->getRepository(\App\Entity\User::class);
        $user = $userRepo->find($userId);

        return $user ? $user->getUsername() : null;
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\TicketSystemType;
use App\Repository\EntryRepository;
use App\Repository\UserRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Generator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function assert;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function sprintf;

class ExportService
{
    public function __construct(private readonly ManagerRegistry $managerRegistry, private readonly JiraOAuthApiFactory $jiraOAuthApiFactory, private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * Returns entries filtered and ordered.
     *
     * @param array<int, int>|null $arProjects
     * @param array<int, int>|null $arUsers
     *
     * @return list<array<string, int|string|null>>
     */
    public function getEntries(User $currentUser, string $strStart = '', string $strEnd = '', ?array $arProjects = null, ?array $arUsers = null): array
    {
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);

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

        $arEntries = $objectRepository->getFilteredEntries(
            $arFilter,
            0, // offset
            0, // limit (0 = no limit)
        );

        $arApi = [];

        $arReturn = [];
        foreach ($arEntries as $arEntry) {
            if (!$arEntry instanceof Entry) {
                continue;
            }

            $arReturn[] = $this->buildEntryRow($arEntry, $arApi, $currentUser);
        }

        return $arReturn;
    }

    /**
     * @param array<int, mixed> $arApi
     *
     * @return array<string, int|string|null>
     */
    private function buildEntryRow(Entry $entry, array &$arApi, User $currentUser): array
    {
        return [
            'id' => $entry->getId(),
            'user' => $entry->getUser() instanceof User ? $entry->getUser()->getUsername() : '',
            'customer' => $entry->getCustomer() instanceof Customer ? $entry->getCustomer()->getName() : '',
            'project' => $entry->getProject() instanceof Project ? $entry->getProject()->getName() : '',
            'activity' => $entry->getActivity() instanceof Activity ? $entry->getActivity()->getName() : '',
            'description' => $entry->getDescription(),
            'start' => $entry->getStart()->format('Y-m-d H:i:s'),
            'end' => $entry->getEnd()->format('Y-m-d H:i:s'),
            'ticket' => $entry->getTicket(),
            'ticket_url' => $this->getTicketUrl($entry, $arApi, $currentUser),
            'worklog_url' => $this->getWorklogUrl($entry, $arApi, $currentUser),
        ];
    }

    /**
     * @param array<int, mixed> $arApi
     */
    private function getTicketUrl(Entry $entry, array &$arApi, User $currentUser): string
    {
        if ('' === $entry->getTicket()) {
            return '';
        }

        $project = $entry->getProject();
        if (!$project instanceof Project) {
            return '';
        }

        $ticketSystem = $project->getTicketSystem();
        if (!$ticketSystem instanceof TicketSystem) {
            return '';
        }

        if ($ticketSystem->getBookTime()
            && TicketSystemType::JIRA === $ticketSystem->getType()
        ) {
            $ticketSystemId = $ticketSystem->getId() ?? '';
            if (!isset($arApi[$ticketSystemId])) {
                $arApi[$ticketSystemId] = $this->jiraOAuthApiFactory->create($currentUser, $ticketSystem);
            }

            if (isset($arApi[$ticketSystemId])) {
                // Use the ticket system's URL template directly
                return sprintf($ticketSystem->getTicketUrl(), $entry->getTicket());
            }
        }

        return sprintf($ticketSystem->getTicketUrl(), $entry->getTicket());
    }

    /**
     * @param array<int, mixed> $arApi
     */
    private function getWorklogUrl(Entry $entry, array &$arApi, User $currentUser): string
    {
        if ('' === $entry->getTicket() || null === $entry->getWorklogId()) {
            return '';
        }

        $project = $entry->getProject();
        if (!$project instanceof Project) {
            return '';
        }

        $ticketSystem = $project->getTicketSystem();
        if (!$ticketSystem instanceof TicketSystem) {
            return '';
        }

        if (!$ticketSystem->getBookTime() || TicketSystemType::JIRA !== $ticketSystem->getType()) {
            return '';
        }

        $ticketSystemId = $ticketSystem->getId() ?? '';
        if (!isset($arApi[$ticketSystemId])) {
            $arApi[$ticketSystemId] = $this->jiraOAuthApiFactory->create($currentUser, $ticketSystem);
        }

        $apiService = $arApi[$ticketSystemId] ?? null;
        if (null === $apiService) {
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
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);

        return $objectRepository->findByDate($userId, $year, $month, $projectId, $customerId, $arSort);
    }

    /**
     * Export entries in batches for memory efficiency.
     *
     * @param array<string, string>|null $arSort Sorting configuration
     *
     * @return Generator<Entry[]>
     */
    public function exportEntriesBatched(int $userId, int $year, int $month, ?int $projectId = null, ?int $customerId = null, ?array $arSort = null, int $batchSize = 1000): Generator
    {
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);

        $offset = 0;
        do {
            $batch = $objectRepository->findByDatePaginated($userId, $year, $month, $projectId, $customerId, $arSort, $offset, $batchSize);
            if ([] !== $batch) {
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

        $entriesByTicketSystem = $this->groupEntriesByTicketSystem($entries);

        // Get user for API calls
        $objectRepository = $this->managerRegistry->getRepository(User::class);
        assert($objectRepository instanceof UserRepository);
        $user = $objectRepository->find($userId);
        if (null === $user) {
            return $entries;
        }

        $fields = [];
        if ($includeBillable) {
            $fields[] = 'labels';
        }

        if ($includeTicketTitle) {
            $fields[] = 'summary';
        }

        if ([] === $fields) {
            return $entries;
        }

        // Fetch ticket information from JIRA for each ticket system
        foreach ($entriesByTicketSystem as $ticketSystemData) {
            $jiraApi = $this->jiraOAuthApiFactory->create($user, $ticketSystemData['ticketSystem']);
            $tickets = array_unique($ticketSystemData['tickets']);

            try {
                $ticketData = $this->fetchTicketData($jiraApi, $tickets, $fields);
                $this->applyTicketData($ticketSystemData['entries'], $ticketData, $includeBillable, $includeTicketTitle);
            } catch (Exception $exception) {
                $this->logger->warning('Export: could not enrich entries with Jira ticket data', [
                    'exception' => $exception,
                ]);

                continue;
            }
        }

        return $entries;
    }

    /**
     * Groups entries by bookable Jira ticket system to minimize API calls.
     *
     * @param Entry[] $entries
     *
     * @return array<int|string, array{ticketSystem: TicketSystem, entries: list<Entry>, tickets: list<string>}>
     */
    private function groupEntriesByTicketSystem(array $entries): array
    {
        $entriesByTicketSystem = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }
            if ('' === $entry->getTicket()) {
                continue;
            }
            if (!$entry->getProject() instanceof Project) {
                continue;
            }
            $ticketSystem = $entry->getProject()->getTicketSystem();
            if (!$ticketSystem instanceof TicketSystem) {
                continue;
            }
            if (!$ticketSystem->getBookTime()) {
                continue;
            }
            if (TicketSystemType::JIRA !== $ticketSystem->getType()) {
                continue;
            }

            $ticketSystemId = $ticketSystem->getId();
            // PHP 8.5 deprecates null as array offset, convert to empty string
            $key = $ticketSystemId ?? '';
            if (!isset($entriesByTicketSystem[$key])) {
                $entriesByTicketSystem[$key] = [
                    'ticketSystem' => $ticketSystem,
                    'entries' => [],
                    'tickets' => [],
                ];
            }

            $entriesByTicketSystem[$key]['entries'][] = $entry;
            $entriesByTicketSystem[$key]['tickets'][] = $entry->getTicket();
        }

        return $entriesByTicketSystem;
    }

    /**
     * Searches Jira for the given tickets and maps ticket key to its fields object.
     *
     * @param array<int, string> $tickets
     * @param array<int, string> $fields
     *
     * @throws Exception
     *
     * @return array<int|string, mixed>
     */
    private function fetchTicketData(JiraOAuthApiService $jiraOAuthApiService, array $tickets, array $fields): array
    {
        // Build JQL query for all tickets
        $jql = sprintf('key in (%s)', implode(',', $tickets));

        $result = $jiraOAuthApiService->searchTicket($jql, $fields, count($tickets));
        $ticketData = [];

        if (is_object($result) && property_exists($result, 'issues') && is_array($result->issues)) {
            foreach ($result->issues as $issue) {
                if (is_object($issue) && property_exists($issue, 'key') && property_exists($issue, 'fields')) {
                    $key = $issue->key;
                    if (is_string($key) || is_int($key)) {
                        $ticketData[$key] = $issue->fields;
                    }
                }
            }
        }

        return $ticketData;
    }

    /**
     * Updates entries with the fetched ticket information.
     *
     * @param list<Entry>              $entries
     * @param array<int|string, mixed> $ticketData
     */
    private function applyTicketData(array $entries, array $ticketData, bool $includeBillable, bool $includeTicketTitle): void
    {
        foreach ($entries as $entry) {
            $ticket = $entry->getTicket();
            if (!isset($ticketData[$ticket])) {
                continue;
            }

            $fields = $ticketData[$ticket];
            if (!is_object($fields)) {
                continue;
            }

            if ($includeBillable) {
                $this->applyBillable($entry, $fields);
            }

            if ($includeTicketTitle) {
                $this->applyTicketTitle($entry, $fields);
            }
        }
    }

    /**
     * Marks the entry billable when the ticket carries the "billable" label.
     */
    private function applyBillable(Entry $entry, object $fields): void
    {
        if (!property_exists($fields, 'labels') || !is_array($fields->labels)) {
            return;
        }

        $entry->setBillable(in_array('billable', $fields->labels, true));
    }

    /**
     * Copies the ticket summary onto the entry.
     */
    private function applyTicketTitle(Entry $entry, object $fields): void
    {
        if (!property_exists($fields, 'summary')) {
            return;
        }

        $summary = $fields->summary;
        if (is_string($summary) || null === $summary) {
            $entry->setTicketTitle($summary);
        }
    }

    /**
     * Get username by user ID for filename generation.
     */
    public function getUsername(int $userId): ?string
    {
        $objectRepository = $this->managerRegistry->getRepository(User::class);
        assert($objectRepository instanceof UserRepository);
        $user = $objectRepository->find($userId);

        return $user instanceof User ? $user->getUsername() : null;
    }
}

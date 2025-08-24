<?php

namespace App\Service;

use App\Entity\Entry;
use Doctrine\Persistence\ManagerRegistry;

class ExportService
{
    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
    }
    /**
     * Returns entries filtered and ordered.
     *
     * @param array<string, bool>|null $arSort
     *
     * @return Entry[]
     *
     * @psalm-return array<int, Entry>
     */
    public function exportEntries(int $userId, int $year, ?int $month, ?int $projectId, ?int $customerId, ?array $arSort = null): array
    {
        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);

        return $objectRepository->findByDate($userId, $year, $month, $projectId, $customerId, $arSort);
    }

    /**
     * Returns user name for given user ID.
     */
    public function getUsername(?int $userId = null): ?string
    {
        $username = 'all';
        if (0 < (int) $userId) {
            /** @var \App\Entity\User $user */
            $user = $this->managerRegistry
                ->getRepository(\App\Entity\User::class)
                ->find($userId);
            if (null !== $user) {
                $username = $user->getUsername();
            }
        }

        return $username;
    }

    /**
     * Adds billable (boolean) property to entries depending on the existence of a "billable" label
     * in associated JIRA issues and optionally adds ticket titles.
     *
     * @param Entry[] $entries
     *
     * @return Entry[]
     *
     * @psalm-return array<Entry>
     */
    public function enrichEntriesWithTicketInformation(
        int $currentUserId,
        array $entries,
        bool $showBillableField,
        bool $removeNotBillable = false,
        bool $showTicketTitles = false,
    ): array {
        $doctrine = $this->managerRegistry;
        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $doctrine->getRepository(\App\Entity\User::class);
        /** @var \App\Entity\User $currentUser */
        $currentUser = $objectRepository->find($currentUserId);

        $arTickets = [];
        $arApi = [];
        foreach ($entries as $entry) {
            if (strlen($entry->getTicket()) > 0
                && $entry->getProject()
                && $entry->getProject()->getTicketSystem()
                && $entry->getProject()->getTicketSystem()->getBookTime()
                && 'JIRA' == $entry->getProject()->getTicketSystem()->getType()
            ) {
                $ticketSystem = $entry->getProject()->getTicketSystem();

                if (!isset($arApi[$ticketSystem->getId()])) {
                    $factory = class_exists(\App\Service\Integration\Jira\JiraOAuthApiFactory::class)
                        ? new \App\Service\Integration\Jira\JiraOAuthApiFactory($this->managerRegistry, new \Symfony\Component\Routing\Generator\UrlGenerator())
                        : null;
                    if ($factory) {
                        $arApi[$ticketSystem->getId()] = $factory->create($currentUser, $ticketSystem);
                    }
                }

                $arTickets[$ticketSystem->getId()][] = $entry->getTicket();
            }
        }

        $maxRequestsElements = 500;
        $arBillable = [];
        $arTicketTitles = [];

        foreach ($arApi as $idx => $jiraApi) {
            $ticketSystemIssuesTotal = array_unique($arTickets[$idx]);
            $ticketSystemIssuesTotalChunks = array_chunk(
                $ticketSystemIssuesTotal,
                $maxRequestsElements
            );

            $jiraFields = [];
            if ($showBillableField) {
                $jiraFields[] = 'labels';
            }

            if ($showTicketTitles) {
                $jiraFields[] = 'summary';
            }

            foreach ($ticketSystemIssuesTotalChunks as $ticketSystemIssueTotalChunk) {
                $ret = $jiraApi->searchTicket(
                    'IssueKey in ('.implode(',', $ticketSystemIssueTotalChunk).')',
                    $jiraFields,
                    500
                );

                if (isset($ret->issues) && is_iterable($ret->issues)) {
                    foreach ($ret->issues as $issue) {
                        $issueKey = is_object($issue) && isset($issue->key) ? (string) $issue->key : null;
                        $issueFields = is_object($issue) && isset($issue->fields) ? $issue->fields : null;

                        if (null !== $issueKey && $showBillableField && is_object($issueFields)) {
                            $labels = $issueFields->labels ?? null;
                            if (is_array($labels) && in_array('billable', $labels, true)) {
                                $arBillable[] = $issueKey;
                            }
                        }

                        if (null !== $issueKey && $showTicketTitles && is_object($issueFields)) {
                            $summary = $issueFields->summary ?? null;
                            if (is_string($summary)) {
                                $arTicketTitles[$issueKey] = $summary;
                            }
                        }
                    }
                }
            }
        }

        foreach ($entries as $key => $entry) {
            if ($showBillableField) {
                $billable = in_array($entry->getTicket(), $arBillable, true);
                if (!$billable) {
                    if ($removeNotBillable) {
                        unset($entries[$key]);
                        continue;
                    }

                // leave billable as-is (e.g., null) when not billable
                } else {
                    $entry->setBillable(true);
                }
            }

            if ($showTicketTitles && method_exists($entry, 'setTicketTitle')) {
                $ticketKey = $entry->getTicket();
                $entry->setTicketTitle($arTicketTitles[$ticketKey] ?? null);
            }
        }

        return $entries;
    }
}

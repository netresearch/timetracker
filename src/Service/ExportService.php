<?php

namespace App\Service;

use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Helper\JiraOAuthApi;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\RouterInterface;

class ExportService
{
    public function __construct(private readonly ManagerRegistry $managerRegistry, private readonly RouterInterface $router, private readonly JiraOAuthApiFactory $jiraApiFactory)
    {
    }

    /**
     * Returns entries filtered and ordered.
     */
    public function exportEntries(int $userId, int $year, ?int $month, ?int $projectId, ?int $customerId, array $arSort = null)
    {
        /** @var \App\Entity\Entry[] $arEntries */
        $arEntries = $this->getEntryRepository()
            ->findByDate($userId, $year, $month, $projectId, $customerId, $arSort);

        return $arEntries;
    }

    /**
     * Returns user name for given user ID.
     */
    public function getUsername($userId = null)
    {
        $username = 'all';
        if (0 < (int) $userId) {
            /** @var \App\Entity\User $user */
            $user = $this->managerRegistry
                ->getRepository(\App\Entity\User::class)
                ->find($userId);
            if ($user !== null) {
                $username = $user->getUsername();
            }
        }

        return $username;
    }

    protected function getEntryRepository()
    {
        return $this->managerRegistry->getRepository(\App\Entity\Entry::class);
    }

    /**
     * Adds billable (boolean) property to entries depending on the existence of a "billable" label
     * in associated JIRA issues and optionally adds ticket titles.
     */
    public function enrichEntriesWithTicketInformation(
        $currentUserId,
        array $entries,
        $showBillableField,
        $removeNotBillable = false,
        $showTicketTitles = false
    ): array {
        $doctrine = $this->managerRegistry;
        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $doctrine->getRepository(\App\Entity\User::class);
        /** @var \App\Entity\User $currentUser */
        $currentUser = $objectRepository->find($currentUserId);

        $router = $this->router;

        $arTickets = [];
        $arApi = [];
        /** @var Entry $entry */
        foreach ($entries as $entry) {
            if (strlen($entry->getTicket()) > 0
                && $entry->getProject()
                && $entry->getProject()->getTicketSystem()
                && $entry->getProject()->getTicketSystem()->getBookTime()
                && $entry->getProject()->getTicketSystem()->getType() == 'JIRA'
            ) {
                /** @var TicketSystem $ticketSystem */
                $ticketSystem = $entry->getProject()->getTicketSystem();

                if (!isset($arApi[$ticketSystem->getId()])) {
                    $arApi[$ticketSystem->getId()] = $this->jiraApiFactory->create($currentUser, $ticketSystem);
                }

                $arTickets[$ticketSystem->getId()][] = $entry->getTicket();
            }
        }

        $maxRequestsElements = 500;
        $arBillable = [];
        $arTicketTitles = [];

        /** @var JiraOAuthApi $jiraApi */
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
                    'IssueKey in (' . implode(',', $ticketSystemIssueTotalChunk) . ')',
                    $jiraFields,
                    '500'
                );

                foreach ($ret->issues as $issue) {
                    if ($showBillableField
                        && isset($issue->fields->labels)
                        && in_array('billable', $issue->fields->labels)
                    ) {
                        $arBillable[] = $issue->key;
                    }

                    if ($showTicketTitles) {
                        $arTicketTitles[$issue->key] = $issue->fields->summary;
                    }
                }
            }
        }

        foreach ($entries as $key => $entry) {
            if ($showBillableField) {
                $billable = in_array($entry->getTicket(), $arBillable);
                if (!$billable && $removeNotBillable) {
                    unset($entries[$key]);
                } else {
                    if (method_exists($entry, 'setBillable')) {
                        $entry->setBillable($billable);
                    } else {
                        $entry->billable = $billable; // legacy fallback
                    }
                }
            }

            if ($showTicketTitles) {
                if (method_exists($entry, 'setTicketTitle')) {
                    $entry->setTicketTitle($arTicketTitles[$entry->getTicket()] ?? null);
                }
            }
        }

        return $entries;
    }
}

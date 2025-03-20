<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

/**
 * Netresearch Timetracker
 *
 * PHP version 5
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Service
 * @author     Michael Lühr <michael.luehr@netresearch.de>
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */

namespace App\Services;

use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Helper\JiraOAuthApi;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Class Export
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Service
 * @author     Michael Lühr <michael.luehr@netresearch.de>
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */
class Export
{
    /**
     * @var ManagerRegistry
     */
    private $doctrine;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * Export constructor
     */
    public function __construct(
        ManagerRegistry $doctrine,
        LoggerInterface $logger,
        RouterInterface $router
    ) {
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->router = $router;
    }

    /**
     * Returns entries filtered and ordered.
     *
     * @param integer $userId     Filter entries by user
     * @param integer $year       Filter entries by year
     * @param integer $month      Filter entries by month
     * @param integer $projectId  Filter entries by project
     * @param integer $customerId Filter entries by customer
     * @param array   $arSort     Sort result by given fields
     *
     * @return mixed
     */
    public function exportEntries($userId, $year, $month, $projectId, $customerId, array $arSort = null)
    {
        /** @var \App\Entity\Entry[] $arEntries */
        $arEntries = $this->getEntryRepository()
            ->findByDate($userId, $year, $month, $projectId, $customerId, $arSort);

        return $arEntries;
    }

    /**
     * Returns user name for given user ID.
     *
     * @param integer $userId User ID
     *
     * @return string $username - the name of the user or all if no valid user id is provided
     */
    public function getUsername($userId = null)
    {
        $username = 'all';
        if (0 < (int) $userId) {
            /** @var \App\Entity\User $user */
            $user = $this->doctrine
                ->getRepository(\App\Entity\User::class)
                ->find($userId);
            if ($user !== null) {
                $username = $user->getUsername();
            }
        }

        return $username;
    }

    /**
     * returns the entry repository
     *
     * @return \App\Repository\EntryRepository
     */
    protected function getEntryRepository()
    {
        return $this->doctrine->getRepository(\App\Entity\Entry::class);
    }

    /**
     * Adds billable (boolean) property to entries depending on the existence
     * of a "billable" label in associated JIRA issues
     *
     * @param int   $currentUserId     logged in users id
     * @param array $entries           entries to export
     * @param bool  $showBillableField Add the "billable" information field
     * @param bool  $removeNotBillable remove not billable entries
     * @param bool  $showTicketTitles  Add ticket title field
     *
     * @return array
     */
    public function enrichEntriesWithTicketInformation(
        $currentUserId, array $entries,
        $showBillableField, $removeNotBillable = false,
        $showTicketTitles = false
    ) {
        $doctrine = $this->doctrine;
        /** @var \App\Repository\UserRepository $userRepository */
        $userRepository = $doctrine->getRepository(\App\Entity\User::class);
        /** @var \App\Entity\User $currentUser */
        $currentUser = $userRepository->find($currentUserId);

        // Use the injected router
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
                    // Create JiraOAuthApi with our service dependencies
                    $arApi[$ticketSystem->getId()] = new JiraOAuthApi(
                        $currentUser,
                        $ticketSystem,
                        $doctrine,
                        $router
                    );
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
                $ticketSystemIssuesTotal, $maxRequestsElements
            );

            $jiraFields = [];
            if ($showBillableField) {
                $jiraFields[] = 'labels';
            }
            if ($showTicketTitles) {
                $jiraFields[] = 'summary';
            }

            if (is_array($ticketSystemIssuesTotalChunks)
                && !empty($ticketSystemIssuesTotalChunks)
            ) {
                foreach ($ticketSystemIssuesTotalChunks as $arIssues) {
                    $ret = $jiraApi->searchTicket(
                        'IssueKey in (' . join(',', $arIssues) . ')',
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
        }

        foreach ($entries as $key => $entry) {
            if ($showBillableField) {
                $billable = in_array($entry->getTicket(), $arBillable);
                if (!$billable && $removeNotBillable) {
                    unset($entries[$key]);
                } else {
                    $entry->billable = $billable;
                }
            }
            if ($showTicketTitles) {
                $entry->setTicketTitle($arTicketTitles[$entry->getTicket()] ?? null);
            }
        }

        return $entries;
    }
}

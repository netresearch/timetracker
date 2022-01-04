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

use App\Entity\Entry as Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Helper\JiraOAuthApi;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
     * mandatory dependency the service container
     *
     * @param ContainerInterface $container
     */
    public function __construct(protected ?\Symfony\Component\DependencyInjection\ContainerInterface $container = null)
    {
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
            /* @var $user User */
            $user = $this->container->get('doctrine')
                ->getRepository('App:User')
                ->find($userId);
            $username = $user->getUsername();
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
        return $this->container->get('doctrine')->getRepository('App:Entry');
    }

    /**
     * Adds billable (boolean) property to entries depending on the existence
     * of a "billable" label in associated JIRA issues
     *
     * @param int   $currentUserId     logged in users id
     * @param array $entries           entries to export
     * @param bool  $removeNotBillable remove not billable entries
     *
     * @return array
     */
    public function enrichEntriesWithBillableInformation(
        $currentUserId, array $entries, $removeNotBillable = false
    ) {
        /* @var $currentUser \App\Entity\User */
        $doctrine = $this->container->get('doctrine');
        $currentUser = $doctrine->getRepository('App:User')
            ->find($currentUserId);

        /** @var Router $router */
        $router = $this->container->get('router');

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
        /** @var JiraOAuthApi $jiraApi */
        foreach ($arApi as $idx => $jiraApi) {
            $ticketSystemIssuesTotal = array_unique($arTickets[$idx]);
            $ticketSystemIssuesTotalChunks = array_chunk(
                $ticketSystemIssuesTotal, $maxRequestsElements
            );

            if (is_array($ticketSystemIssuesTotalChunks)
                && !empty($ticketSystemIssuesTotalChunks)
            ) {
                foreach ($ticketSystemIssuesTotalChunks as $arIssues) {
                    $ret = $jiraApi->searchTicket(
                        'IssueKey in (' . join(',', $arIssues) . ')',
                        ['labels'],
                        '500'
                    );

                    foreach ($ret->issues as $issue) {
                        if (isset($issue->fields->labels)
                            && in_array('billable', $issue->fields->labels)
                        ) {
                            $arBillable[] = $issue->key;
                        }
                    }
                }
            }
        }

        foreach ($entries as $key => $entry) {
            $billable = in_array($entry->getTicket(), $arBillable);
            if (!$billable && $removeNotBillable) {
                unset($entries[$key]);
            } else {
                $entry->billable = $billable;
            }
        }

        return $entries;
    }
}

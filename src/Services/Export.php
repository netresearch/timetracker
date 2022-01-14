<?php declare(strict_types=1);
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

/**
 * Netresearch Timetracker.
 *
 * PHP version 5
 *
 * @category   Netresearch
 *
 * @author     Michael Lühr <michael.luehr@netresearch.de>
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */

namespace App\Services;

use Symfony\Component\Routing\RouterInterface;
use App\Repository\EntryRepository;
use App\Entity\Entry as Entry;
use App\Entity\TicketSystem;
use App\Helper\JiraOAuthApi;
use App\Repository\UserRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class Export.
 *
 * @category   Netresearch
 *
 * @author     Michael Lühr <michael.luehr@netresearch.de>
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */
class Export
{
    /**
     * mandatory dependency the service container.
     */
    public function __construct(
        protected ?ContainerInterface $container,
        protected ManagerRegistry $doctrine,
        private readonly RouterInterface $router,
        protected UserRepository $userRepo,
        protected EntryRepository $entryRepo
    ) {
    }

    /**
     * Returns entries filtered and ordered.
     */
    public function exportEntries(int $userId, int $year, int $month, int $projectId, int $customerId, array $arSort = null): array
    {
        return $this->entryRepo->findByDate($userId, $year, $month, $projectId, $customerId, $arSort);
    }

    /**
     * Returns user name for given user ID.
     *
     * @return string $username - the name of the user or all if no valid user id is provided
     */
    public function getUsername(int $userId = null): string
    {
        $username = 'all';
        if (0 < (int) $userId) {
            $user = $this->userRepo->find($userId);
            $username = $user->getUsername();
        }

        return $username;
    }

    /**
     * Adds billable (boolean) property to entries depending on the existence
     * of a "billable" label in associated JIRA issues.
     *
     * @param int   $currentUserId     logged in users id
     * @param array $entries           entries to export
     * @param bool  $removeNotBillable remove not billable entries
     *
     * @return array
     */
    public function enrichEntriesWithBillableInformation(
        int $currentUserId,
        array $entries,
        bool $removeNotBillable = false
    ): array {
        $currentUser = $this->userRepo->find($currentUserId);

        /** @var Router $router */
        $router = $this->router;

        $arTickets = [];
        $arApi     = [];
        /** @var Entry $entry */
        foreach ($entries as $entry) {
            if ($entry->getTicket() !== ''
                && $entry->getProject()
                && $entry->getProject()->getTicketSystem()
                && $entry->getProject()->getTicketSystem()->getBookTime()
                && 'JIRA' === $entry->getProject()->getTicketSystem()->getType()
            ) {
                /** @var TicketSystem $ticketSystem */
                $ticketSystem = $entry->getProject()->getTicketSystem();

                if (!isset($arApi[$ticketSystem->getId()])) {
                    $arApi[$ticketSystem->getId()] = new JiraOAuthApi(
                        $currentUser,
                        $ticketSystem,
                        $this->doctrine,
                        $router
                    );
                }

                $arTickets[$ticketSystem->getId()][] = $entry->getTicket();
            }
        }

        $maxRequestsElements = 500;
        $arBillable          = [];
        /** @var JiraOAuthApi $jiraApi */
        foreach ($arApi as $idx => $jiraApi) {
            $ticketSystemIssuesTotal       = array_unique($arTickets[$idx]);
            $ticketSystemIssuesTotalChunks = array_chunk(
                $ticketSystemIssuesTotal,
                $maxRequestsElements
            );

            if (\is_array($ticketSystemIssuesTotalChunks)
                && !empty($ticketSystemIssuesTotalChunks)
            ) {
                foreach ($ticketSystemIssuesTotalChunks as $arIssues) {
                    $ret = $jiraApi->searchTicket(
                        'IssueKey in ('.implode(',', $arIssues).')',
                        ['labels'],
                        500
                    );

                    foreach ($ret->issues as $issue) {
                        if (isset($issue->fields->labels)
                            && \in_array('billable', $issue->fields->labels, true)
                        ) {
                            $arBillable[] = $issue->key;
                        }
                    }
                }
            }
        }

        foreach ($entries as $key => $entry) {
            $billable = \in_array($entry->getTicket(), $arBillable, true);
            if (!$billable && $removeNotBillable) {
                unset($entries[$key]);
            } else {
                $entry->billable = $billable;
            }
        }

        return $entries;
    }
}

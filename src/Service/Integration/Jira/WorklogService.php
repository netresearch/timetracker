<?php

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Exception\Integration\Jira\JiraApiException;
use App\Helper\JiraOAuthApi;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\RouterInterface;

/**
 * Service for managing Jira worklogs.
 */
class WorklogService
{
    /**
     * @var \Doctrine\Persistence\ManagerRegistry
     */
    private $doctrine;

    /**
     * @var \Symfony\Component\Routing\RouterInterface
     */
    private $router;

    public function __construct(
        ManagerRegistry $doctrine,
        RouterInterface $router
    ) {
        $this->doctrine = $doctrine;
        $this->router = $router;
    }

    /**
     * Deletes a work log entry in a remote JIRA installation.
     * JIRA instance is defined by ticket system in project.
     *
     * @param Entry $entry The entry containing the worklog to delete
     * @param TicketSystem|null $ticketSystem Optional ticket system override
     * @throws JiraApiException If there's an error with the Jira API
     */
    public function deleteWorklog(
        Entry $entry,
        TicketSystem $ticketSystem = null
    ): void {
        $project = $entry->getProject();
        if (!$project instanceof Project) {
            return;
        }

        if (!$ticketSystem instanceof TicketSystem) {
            $ticketSystem = $project->getTicketSystem();
        }

        if ($project->hasInternalJiraProjectKey()) {
            /** @var \App\Repository\TicketSystemRepository $ticketSystemRepo */
            $ticketSystemRepo = $this->doctrine->getRepository(TicketSystem::class);
            $ticketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());
        }

        if (!$ticketSystem instanceof TicketSystem) {
            return;
        }

        if (!$ticketSystem->getBookTime() || $ticketSystem->getType() != 'JIRA') {
            return;
        }

        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $ticketSystem,
            $this->doctrine,
            $this->router
        );
        $jiraOAuthApi->deleteEntryJiraWorkLog($entry);
    }

    /**
     * Updates a work log entry in a remote JIRA installation.
     * JIRA instance is defined by ticket system in project.
     *
     * @param Entry $entry The new entry data
     * @param Entry $oldEntry The old entry data
     * @param TicketSystem|null $ticketSystem Optional ticket system override
     * @throws JiraApiException If there's an error with the Jira API
     */
    public function updateWorklog(
        Entry $entry,
        Entry $oldEntry,
        TicketSystem $ticketSystem = null
    ): void {
        $project = $entry->getProject();
        if (!$project instanceof Project) {
            return;
        }

        if (!$ticketSystem instanceof TicketSystem) {
            $ticketSystem = $project->getTicketSystem();
        }

        if ($project->hasInternalJiraProjectKey()) {
            /** @var \App\Repository\TicketSystemRepository $ticketSystemRepo */
            $ticketSystemRepo = $this->doctrine->getRepository(TicketSystem::class);
            $ticketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());
        }

        if (!$ticketSystem instanceof TicketSystem) {
            return;
        }

        if (!$ticketSystem->getBookTime() || $ticketSystem->getType() != 'JIRA') {
            return;
        }

        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $ticketSystem,
            $this->doctrine,
            $this->router
        );

        // If the ticket changed, delete the old worklog and create a new one
        if ($this->shouldTicketBeDeleted($entry, $oldEntry)) {
            $jiraOAuthApi->deleteEntryJiraWorkLog($oldEntry);
            $jiraOAuthApi->updateEntryJiraWorkLog($entry);
            return;
        }

        // Otherwise just update the existing worklog
        $jiraOAuthApi->updateEntryJiraWorkLog($entry);
    }

    /**
     * Determines if a ticket should be deleted based on changes to the entry.
     *
     * @param Entry $entry The new entry
     * @param Entry $oldEntry The old entry
     * @return bool True if the old worklog should be deleted
     */
    private function shouldTicketBeDeleted(Entry $entry, Entry $oldEntry): bool
    {
        // Ticket changed
        if ($entry->getTicket() != $oldEntry->getTicket()) {
            return true;
        }

        // Project changed
        if ($entry->getProject() && $oldEntry->getProject() &&
            $entry->getProject()->getId() != $oldEntry->getProject()->getId()) {
            return true;
        }

        return false;
    }
}

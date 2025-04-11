<?php

declare(strict_types=1);

namespace App\Service\TimeEntry;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Helper\JiraOAuthApi;
use App\Helper\TicketHelper;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service for managing time entries.
 */
class TimeEntryService
{
    /**
     * @var \Doctrine\Persistence\ManagerRegistry
     */
    private $doctrine;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Symfony\Component\Routing\RouterInterface
     */
    private $router;

    /**
     * @var \Symfony\Contracts\Translation\TranslatorInterface
     */
    private $translator;

    public function __construct(
        ManagerRegistry $doctrine,
        LoggerInterface $trackingLogger,
        RouterInterface $router,
        TranslatorInterface $translator
    ) {
        $this->doctrine = $doctrine;
        $this->logger = $trackingLogger;
        $this->router = $router;
        $this->translator = $translator;
    }

    /**
     * Delete a time entry.
     *
     * @param Entry $entry The entry to delete
     * @return array Result with success status and optional alert message
     * @throws JiraApiUnauthorizedException If user is not authenticated with Jira
     */
    public function deleteEntry(Entry $entry): array
    {
        $alert = null;

        try {
            $this->deleteJiraWorklog($entry);
        } catch (JiraApiUnauthorizedException $e) {
            // Re-throw to be handled by the controller
            throw $e;
        } catch (JiraApiException $e) {
            $alert = $e->getMessage() . '<br />' .
                $this->translator->trans("Dataset was modified in Timetracker anyway");
        }

        // Remember the day to calculate classes afterwards
        $day = $entry->getDay()->format("Y-m-d");
        $userId = $entry->getUser()->getId();

        $manager = $this->doctrine->getManager();
        $manager->remove($entry);
        $manager->flush();

        // We have to update classes after deletion as well
        $this->calculateClasses($userId, $day);

        return ['success' => true, 'alert' => $alert];
    }

    /**
     * Deletes a work log entry in a remote JIRA installation.
     * JIRA instance is defined by ticket system in project.
     *
     * @param Entry $entry The entry containing the worklog to delete
     * @param TicketSystem|null $ticketSystem Optional ticket system override
     * @throws JiraApiException If there's an error with the Jira API
     */
    private function deleteJiraWorklog(
        Entry $entry,
        TicketSystem $ticketSystem = null
    ): void {
        $project = $entry->getProject();
        if (! $project instanceof Project) {
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

        if (! $ticketSystem instanceof TicketSystem) {
            return;
        }

        if (! $ticketSystem->getBookTime() || $ticketSystem->getType() != 'JIRA') {
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
     * Set rendering classes for pause, overlap and daybreak.
     *
     * @param integer $userId The user ID
     * @param string $day The day in Y-m-d format
     */
    private function calculateClasses($userId, $day): void
    {
        if ((int) $userId === 0) {
            return;
        }

        $managerRegistry = $this->doctrine;
        $objectManager = $managerRegistry->getManager();
        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Entry::class);
        /** @var Entry[] $entries */
        $entries = $objectRepository->findByDay((int) $userId, $day);

        if (!count($entries)) {
            return;
        }

        if (! is_object($entries[0])) {
            return;
        }

        $entry = $entries[0];
        if ($entry->getClass() != Entry::CLASS_DAYBREAK) {
            $entry->setClass(Entry::CLASS_DAYBREAK);
            $objectManager->persist($entry);
            $objectManager->flush();
        }

        $counter = count($entries);

        for ($c = 1; $c < $counter; $c++) {
            $entry = $entries[$c];
            $previous = $entries[$c - 1];

            if ($entry->getStart()->format("H:i") > $previous->getEnd()->format("H:i")) {
                if ($entry->getClass() != Entry::CLASS_PAUSE) {
                    $entry->setClass(Entry::CLASS_PAUSE);
                    $objectManager->persist($entry);
                    $objectManager->flush();
                }

                continue;
            }

            if ($entry->getStart()->format("H:i") < $previous->getEnd()->format("H:i")) {
                if ($entry->getClass() != Entry::CLASS_OVERLAP) {
                    $entry->setClass(Entry::CLASS_OVERLAP);
                    $objectManager->persist($entry);
                    $objectManager->flush();
                }

                continue;
            }

            if ($entry->getClass() != Entry::CLASS_PLAIN) {
                $entry->setClass(Entry::CLASS_PLAIN);
                $objectManager->persist($entry);
                $objectManager->flush();
            }
        }
    }

    /**
     * Creates a ticket in the remote ticket system.
     *
     * @param Entry $entry The entry with ticket information
     * @param TicketSystem|null $ticketSystem Optional ticket system override
     * @return mixed The created ticket or null if no ticket was created
     * @throws JiraApiException If there's an error with the Jira API
     */
    protected function createTicket(
        Entry $entry,
        TicketSystem $ticketSystem = null
    ): mixed {
        $project = $entry->getProject();
        if (!$project) {
            return null;
        }

        if (!$ticketSystem instanceof TicketSystem) {
            $ticketSystem = $project->getTicketSystem();
        }

        if (!$ticketSystem || $ticketSystem->getType() != 'JIRA') {
            return null;
        }

        $jiraOAuthApi = new JiraOAuthApi($entry->getUser(), $ticketSystem, $this->doctrine, $this->router);
        return $jiraOAuthApi->createTicket($entry);
    }

    /**
     * Log data for debugging purposes.
     *
     * @param array $data Data to log
     * @param bool $raw Whether to log raw data or formatted
     */
    private function logData(array $data, bool $raw = false): void
    {
        if ($raw) {
            $this->logger->debug(json_encode($data));
            return;
        }

        $this->logger->debug(print_r($data, true));
    }
}

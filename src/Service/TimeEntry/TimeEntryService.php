<?php

declare(strict_types=1);

namespace App\Service\TimeEntry;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\Activity;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Helper\JiraOAuthApi;
use App\Helper\TicketHelper;
use App\Service\Integration\Jira\WorklogService;
use App\Service\Ticket\TicketValidationService;
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

    /**
     * @var WorklogService|null
     */
    private $worklogService;

    /**
     * @var TicketValidationService|null
     */
    private $ticketValidationService;

    /**
     * @var ClassCalculationService|null
     */
    private $classCalculationService;

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
     * @required
     */
    public function setWorklogService(WorklogService $worklogService): void
    {
        $this->worklogService = $worklogService;
    }

    /**
     * @required
     */
    public function setTicketValidationService(TicketValidationService $ticketValidationService): void
    {
        $this->ticketValidationService = $ticketValidationService;
    }

    /**
     * @required
     */
    public function setClassCalculationService(ClassCalculationService $classCalculationService): void
    {
        $this->classCalculationService = $classCalculationService;
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
        if ($this->classCalculationService) {
            $this->classCalculationService->calculateClasses($userId, $day);
        } else {
            $this->calculateClasses($userId, $day);
        }

        return ['success' => true, 'alert' => $alert];
    }

    /**
     * Save a time entry (create or update).
     *
     * @param array $data Entry data from request
     * @param int $userId User ID
     * @return array Result with entry data and optional alert message
     * @throws \Exception If validation fails or other errors occur
     */
    public function saveEntry(array $data, int $userId): array
    {
        $alert = null;
        $this->logData($data, true);

        $entry = $data['id'] != 0
            ? $this->doctrine->getRepository(Entry::class)->find($data['id'])
            : new Entry();

        // Make a copy to determine if we have to update JIRA
        $oldEntry = clone $entry;

        // Set Project
        if ($project = $this->doctrine->getRepository(Project::class)->find($data['project'])) {
            if (!$project->getActive()) {
                throw new \Exception($this->translator->trans("This project is inactive and cannot be used for booking."));
            }
            $entry->setProject($project);
        }

        // Set Customer
        if ($customer = $this->doctrine->getRepository(\App\Entity\Customer::class)->find($data['customer'])) {
            if (!$customer->getActive()) {
                throw new \Exception($this->translator->trans("This customer is inactive and cannot be used for booking."));
            }
            $entry->setCustomer($customer);
        }

        // Set User
        $user = $this->doctrine->getRepository(User::class)->find($userId);
        $entry->setUser($user);

        // Validate against ticket system
        if ($project->hasInternalJiraProjectKey()) {
            $ticketSystemRepo = $this->doctrine->getRepository(TicketSystem::class);
            $ticketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());
        } else {
            $ticketSystem = $project->getTicketSystem();
        }

        if ($ticketSystem !== null) {
            if (!$ticketSystem instanceof TicketSystem) {
                throw new \Exception($this->translator->trans('Settings for the ticket system need to be checked'));
            }

            $jiraOAuthApi = new JiraOAuthApi(
                $entry->getUser(),
                $ticketSystem,
                $this->doctrine,
                $this->router
            );

            // Tickets do not exist for external project tickets booked on internal ticket system
            // so no need to check for existence - they are created automatically
            if (!$project->hasInternalJiraProjectKey()
                && ($data['ticket'] !== '' && !$jiraOAuthApi->doesTicketExist($data['ticket']))) {
                throw new \Exception($data['ticket'] . ' does not exist');
            }
        }

        // Set Activity
        if ($activity = $this->doctrine->getRepository(Activity::class)->find($data['activity'])) {
            $entry->setActivity($activity);
        }

        // Set basic entry data
        $entry->setTicket(strtoupper(trim((string) ($data['ticket'] ?? ''))))
            ->setDescription($data['description'] ?? '')
            ->setDay($data['date'] ?? null)
            ->setStart($data['start'] ?? null)
            ->setEnd($data['end'] ?? null)
            ->setInternalJiraTicketOriginalKey($data['extTicket'] ?? null)
            ->calcDuration()
            ->setSyncedToTicketsystem(false);

        // Write log
        $this->logData($entry->toArray());

        // Check if the activity needs a ticket
        if ($user->getType() == 'DEV' && $activity instanceof Activity
            && $activity->getNeedsTicket() && strlen((string) $entry->getTicket()) < 1) {
            throw new \Exception($this->translator->trans(
                "For the activity '%activity%' you must specify a ticket.",
                ['%activity%' => $activity->getName()]
            ));
        }

        // Validate ticket format and prefix
        if ($this->ticketValidationService) {
            try {
                $this->ticketValidationService->validateTicketFormat($entry->getTicket());
                $this->ticketValidationService->validateTicketPrefix($entry->getProject(), $entry->getTicket());
            } catch (\InvalidArgumentException $e) {
                throw new \Exception($e->getMessage());
            }
        } else {
            // Fallback to old implementation if service is not available
            $this->requireValidTicketFormat($entry->getTicket());
            $this->requireValidTicketPrefix($entry->getProject(), $entry->getTicket());
        }

        // Save the entry
        $em = $this->doctrine->getManager();
        $em->persist($entry);
        $em->flush();

        // Handle internal Jira ticket system
        try {
            $this->handleInternalJiraTicketSystem($entry, $oldEntry);
        } catch (\Throwable $exception) {
            $alert = $exception->getMessage();
        }

        // Update entry classes for current day and previous day if it changed
        if ($entry->getDay() !== null) {
            if ($this->classCalculationService) {
                $this->classCalculationService->calculateClasses(
                    $user->getId(),
                    $entry->getDay()->format("Y-m-d")
                );

                // Update the previous day's classes if the day changed
                if ($oldEntry->getDay() !== null &&
                    $entry->getDay()->format("Y-m-d") != $oldEntry->getDay()->format("Y-m-d")) {
                    $this->classCalculationService->calculateClasses(
                        $user->getId(),
                        $oldEntry->getDay()->format("Y-m-d")
                    );
                }
            } else {
                // Fallback to old implementation
                $this->calculateClasses(
                    $user->getId(),
                    $entry->getDay()->format("Y-m-d")
                );

                if ($oldEntry->getDay() !== null &&
                    $entry->getDay()->format("Y-m-d") != $oldEntry->getDay()->format("Y-m-d")) {
                    $this->calculateClasses(
                        $user->getId(),
                        $oldEntry->getDay()->format("Y-m-d")
                    );
                }
            }
        }

        // Update JIRA, if necessary
        try {
            if ($this->worklogService) {
                $this->worklogService->updateWorklog($entry, $oldEntry);
            } else {
                $this->updateJiraWorklog($entry, $oldEntry);
            }

            // Save potential work log ID
            $em->persist($entry);
            $em->flush();
        } catch (JiraApiException $e) {
            if ($e instanceof JiraApiUnauthorizedException) {
                throw $e;
            }

            $alert = $e->getMessage() . '<br />' .
                $this->translator->trans("Dataset was modified in Timetracker anyway");
        }

        return [
            'result' => $entry->toArray(),
            'alert' => $alert
        ];
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
        if ($this->worklogService) {
            $this->worklogService->deleteWorklog($entry, $ticketSystem);
            return;
        }

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
     * Updates a work log entry in a remote JIRA installation.
     */
    private function updateJiraWorklog(
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
            $ticketSystemRepo = $this->doctrine->getRepository(TicketSystem::class);
            $ticketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());
        }

        if (!$ticketSystem instanceof TicketSystem) {
            return;
        }

        if (!$ticketSystem->getBookTime() || $ticketSystem->getType() != 'JIRA') {
            return;
        }

        if ($this->shouldTicketBeDeleted($entry, $oldEntry)) {
            // Ticket number changed, delete old work log - new one will be created later
            $this->deleteJiraWorklog($oldEntry, $ticketSystem);
            $entry->setWorklogId(null);
        }

        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $ticketSystem,
            $this->doctrine,
            $this->router
        );
        $jiraOAuthApi->updateEntryJiraWorkLog($entry);
    }

    /**
     * Handles the entry for the configured internal ticketsystem.
     */
    private function handleInternalJiraTicketSystem(Entry $entry, Entry $oldEntry): void
    {
        $project = $entry->getProject();

        $internalJiraTicketSystem = $project->getInternalJiraTicketSystem();
        $internalJiraProjectKey = $project->getInternalJiraProjectKey();

        // If we do not have an internal ticket system or project key, we could do nothing here
        if (empty($internalJiraTicketSystem) || empty($internalJiraProjectKey)) {
            return;
        }

        // If we continue an existing ticket which has been already booked
        // to an internal ticket, we need to use its original key to find
        // the ticket in internal jira
        $strTicket = $entry->getTicket();
        if ($entry->hasInternalJiraTicketOriginalKey()) {
            $strTicket = $entry->getInternalJiraTicketOriginalKey();
        }

        $strOldEntryTicket = $oldEntry->getTicket();
        if ($oldEntry->hasInternalJiraTicketOriginalKey()) {
            $strOldEntryTicket = $oldEntry->getInternalJiraTicketOriginalKey();
        }

        // Get ticket system for internal work log
        $internalJiraTicketSystem = $this->doctrine
            ->getRepository(TicketSystem::class)
            ->find($internalJiraTicketSystem);

        // Check if issue exists
        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $internalJiraTicketSystem,
            $this->doctrine,
            $this->router
        );

        $searchResult = $jiraOAuthApi->searchTicket(
            sprintf(
                'project = %s AND summary ~ %s',
                $project->getInternalJiraProjectKey(),
                $strTicket
            ),
            ['summary', 'description', 'worklog']
        );

        if (!isset($searchResult->issues[0])) {
            // Issue does not exist in internal Jira yet
            $ticket = $this->createTicket($entry, $internalJiraTicketSystem);
            $entry->setTicket($ticket);
        }

        // Update the internal Jira worklog
        if ($this->worklogService) {
            $this->worklogService->updateWorklog($entry, $oldEntry, $internalJiraTicketSystem);
        } else {
            $this->updateJiraWorklog($entry, $oldEntry, $internalJiraTicketSystem);
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
     * Determines if a ticket should be deleted based on changes to the entry.
     */
    private function shouldTicketBeDeleted(Entry $entry, Entry $oldEntry): bool
    {
        $bDifferentTickets = $oldEntry->getTicket() != $entry->getTicket();
        $bIsCurrentTicketOriginalTicket = $entry->getInternalJiraTicketOriginalKey() === $entry->getTicket();

        return !$bIsCurrentTicketOriginalTicket && $bDifferentTickets;
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
     * Validates ticket format.
     */
    private function requireValidTicketFormat($ticket): void
    {
        // Do not check empty tickets
        if (strlen((string) $ticket) < 1) {
            return;
        }

        if (!TicketHelper::checkFormat($ticket)) {
            $message = $this->translator->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }
    }

    /**
     * Validates ticket prefix against project.
     */
    private function requireValidTicketPrefix(Project $project, $ticket): void
    {
        // Do not check empty tickets
        if (strlen($ticket) < 1) {
            return;
        }

        // Do not check empty jira-projects
        if (strlen((string) $project->getJiraId()) < 1) {
            return;
        }

        if (!TicketHelper::checkFormat($ticket)) {
            $message = $this->translator->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }

        $jiraId = TicketHelper::getPrefix($ticket);
        $projectIds = explode(",", (string) $project->getJiraId());

        foreach ($projectIds as $projectId) {
            if (trim($projectId) == $jiraId || $project->matchesInternalJiraProject($jiraId)) {
                return;
            }
        }

        $message = $this->translator->trans(
            "The ticket's Jira ID '%ticket_jira_id%' does not match the project's Jira ID '%project_jira_id%'.",
            ['%ticket_jira_id%' => $jiraId, '%project_jira_id%' => $project->getJiraId()]
        );

        throw new \Exception($message);
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

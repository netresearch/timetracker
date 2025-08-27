<?php
declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Controller\BaseController;
use App\Entity\Activity;
use App\Entity\Contract;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Model\Response;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Util\TicketService;
use App\Util\RequestEntityHelper;
use Psr\Log\LoggerInterface;

abstract class BaseTrackingController extends BaseController
{
    protected ?TicketService $ticketService = null;
    protected ?LoggerInterface $logger = null;
    protected ?JiraOAuthApiFactory $jiraOAuthApiFactory = null;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setLogger(LoggerInterface $trackingLogger): void
    {
        $this->logger = $trackingLogger;
    }

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraOAuthApiFactory): void
    {
        $this->jiraOAuthApiFactory = $jiraOAuthApiFactory;
    }

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTicketService(TicketService $ticketService): void
    {
        $this->ticketService = $ticketService;
    }

    /**
     * Deletes a work log entry in a remote JIRA installation.
     * JIRA instance is defined by ticket system in project.
     *
     * @throws JiraApiException
     */
    protected function deleteJiraWorklog(
        Entry $entry,
        ?TicketSystem $ticketSystem = null,
    ): void {
        $project = $entry->getProject();

        if (!$ticketSystem instanceof TicketSystem) {
            $ticketSystem = $project instanceof Project ? $project->getTicketSystem() : null;
        }

        if ($project && $project->hasInternalJiraProjectKey()) {
            /** @var \App\Repository\TicketSystemRepository $ticketSystemRepo */
            $ticketSystemRepo = $this->managerRegistry->getRepository(TicketSystem::class);
            $ticketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());
        }

        if (!$ticketSystem instanceof TicketSystem) {
            return;
        }

        if (!$ticketSystem->getBookTime() || 'JIRA' != $ticketSystem->getType()) {
            return;
        }

        if (!$this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory || !$entry->getUser() instanceof User) {
            return;
        }
        $jiraOAuthApi = $this->jiraOAuthApiFactory->create($entry->getUser(), $ticketSystem);
        $jiraOAuthApi->deleteEntryJiraWorkLog($entry);
    }

    /**
     * Set rendering classes for pause, overlap and daybreak.
     */
    protected function calculateClasses(int $userId, string $day): void
    {
        if (0 === $userId) {
            return;
        }

        $managerRegistry = $this->managerRegistry;
        $objectManager = $managerRegistry->getManager();
        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Entry::class);
        /** @var Entry[] $entries */
        $entries = $objectRepository->findByDay($userId, $day);

        if (!count($entries)) {
            return;
        }

        $entry = $entries[0];
        if (Entry::CLASS_DAYBREAK != $entry->getClass()) {
            $entry->setClass(Entry::CLASS_DAYBREAK);
            $objectManager->persist($entry);
            $objectManager->flush();
        }

        $counter = count($entries);

        for ($c = 1; $c < $counter; ++$c) {
            $entry = $entries[$c];
            $previous = $entries[$c - 1];

            if (($entry->getStart() instanceof \DateTime)
                && ($previous->getEnd() instanceof \DateTime)
                && ($entry->getStart()->format('H:i') > $previous->getEnd()->format('H:i'))
            ) {
                if (Entry::CLASS_PAUSE != $entry->getClass()) {
                    $entry->setClass(Entry::CLASS_PAUSE);
                    $objectManager->persist($entry);
                    $objectManager->flush();
                }

                continue;
            }

            if (($entry->getStart() instanceof \DateTime)
                && ($previous->getEnd() instanceof \DateTime)
                && ($entry->getStart()->format('H:i') < $previous->getEnd()->format('H:i'))
            ) {
                if (Entry::CLASS_OVERLAP != $entry->getClass()) {
                    $entry->setClass(Entry::CLASS_OVERLAP);
                    $objectManager->persist($entry);
                    $objectManager->flush();
                }

                continue;
            }

            if (Entry::CLASS_PLAIN != $entry->getClass()) {
                $entry->setClass(Entry::CLASS_PLAIN);
                $objectManager->persist($entry);
                $objectManager->flush();
            }
        }
    }

    /**
     * Ensures valid ticket number format.
     *
     * @throws \Exception
     */
    protected function requireValidTicketFormat(string $ticket): void
    {
        if (strlen($ticket) < 1) {
            return;
        }

        if ($this->ticketService && !$this->ticketService->checkFormat($ticket)) {
            $message = $this->translator->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }
    }

    /**
     * TTT-199: check if ticket prefix matches project's Jira id.
     *
     * @throws \Exception
     */
    protected function requireValidTicketPrefix(Project $project, string $ticket): void
    {
        if (strlen($ticket) < 1) {
            return;
        }

        if (strlen((string) $project->getJiraId()) < 1) {
            return;
        }

        if ($this->ticketService && !$this->ticketService->checkFormat($ticket)) {
            $message = $this->translator->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }

        $jiraId = $this->ticketService ? $this->ticketService->getPrefix($ticket) : null;
        if (null === $jiraId) {
            $message = $this->translator->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }

        $projectIds = explode(',', (string) $project->getJiraId());

        foreach ($projectIds as $projectId) {
            if (trim($projectId) === $jiraId || $project->matchesInternalJiraProject($jiraId)) {
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
     * Write log entry using Symfony's logging.
     *
     * @param array<string, mixed>|list<mixed> $data
     */
    protected function logData(array $data, bool $raw = false): void
    {
        $context = [
            'type' => ($raw ? 'raw' : 'obj'),
            'data' => $data,
        ];

        if ($this->logger instanceof LoggerInterface) {
            $this->logger->info('Tracking data', $context);
        }
    }

    /**
     * Updates a JIRA work log entry.
     *
     * @throws JiraApiException
     */
    protected function updateJiraWorklog(
        Entry $entry,
        Entry $oldEntry,
        ?TicketSystem $ticketSystem = null,
    ): void {
        $project = $entry->getProject();

        if (!$ticketSystem instanceof TicketSystem) {
            $ticketSystem = $project instanceof Project ? $project->getTicketSystem() : null;
        }

        if (!$ticketSystem instanceof TicketSystem) {
            return;
        }

        if (!$ticketSystem->getBookTime() || 'JIRA' != $ticketSystem->getType()) {
            return;
        }

        if ($this->shouldTicketBeDeleted($entry, $oldEntry)) {
            $this->deleteJiraWorklog($oldEntry, $ticketSystem);
            $entry->setWorklogId(null);
        }

        if (!$this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory || !$entry->getUser() instanceof User) {
            return;
        }
        $jiraOAuthApi = $this->jiraOAuthApiFactory->create($entry->getUser(), $ticketSystem);
        $jiraOAuthApi->updateEntryJiraWorkLog($entry);
    }

    /**
     * Creates a Ticket in the given ticketSystem.
     */
    protected function createTicket(
        Entry $entry,
        ?TicketSystem $ticketSystem = null,
    ): mixed {
        if (!$ticketSystem instanceof TicketSystem) {
            $project = $entry->getProject();
            $ticketSystem = $project instanceof Project ? $project->getTicketSystem() : null;
        }

        if (!$ticketSystem instanceof TicketSystem) {
            throw new JiraApiException('No ticket system configured for project');
        }

        if (!$this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory || !$entry->getUser() instanceof User) {
            throw new JiraApiException('JIRA API factory or user not available');
        }
        $jiraOAuthApi = $this->jiraOAuthApiFactory->create($entry->getUser(), $ticketSystem);

        return $jiraOAuthApi->createTicket($entry);
    }

    /**
     * Handles the entry for the configured internal ticketsystem.
     */
    protected function handleInternalJiraTicketSystem(Entry $entry, Entry $oldEntry): void
    {
        $project = $entry->getProject();

        if (!$project instanceof Project) {
            return;
        }

        $internalJiraTicketSystem = $project->getInternalJiraTicketSystem();
        $internalJiraProjectKey = $project->getInternalJiraProjectKey();

        if (empty($internalJiraTicketSystem)) {
            return;
        }

        if (empty($internalJiraProjectKey)) {
            return;
        }

        $strTicket = $entry->getTicket();
        if ($entry->hasInternalJiraTicketOriginalKey()) {
            $strTicket = $entry->getInternalJiraTicketOriginalKey();
        }

        $strOdlEntryTicket = $oldEntry->getTicket();
        if ($oldEntry->hasInternalJiraTicketOriginalKey()) {
            $strOdlEntryTicket = $oldEntry->getInternalJiraTicketOriginalKey();
        }

        $internalJiraTicketSystem = $this->managerRegistry
                ->getRepository(TicketSystem::class)
                ->find($internalJiraTicketSystem);

        if (!$internalJiraTicketSystem instanceof TicketSystem) {
            return;
        }

        if (!$this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory || !$entry->getUser() instanceof User) {
            return;
        }
        $jiraOAuthApi = $this->jiraOAuthApiFactory->create($entry->getUser(), $internalJiraTicketSystem);
        $searchResult = $jiraOAuthApi->searchTicket(
            sprintf(
                'project = %s AND summary ~ %s',
                $project->getInternalJiraProjectKey(),
                $strTicket
            ),
            ['key', 'summary'],
            1
        );

        if (count($searchResult->issues) > 0) {
            $issue = reset($searchResult->issues);
        } else {
            $issue = $this->createTicket($entry, $internalJiraTicketSystem);
        }

        $entry->setInternalJiraTicketOriginalKey($strTicket);
        if (!is_object($issue) || !property_exists($issue, 'key')) {
            throw new \RuntimeException('Invalid issue response');
        }

        $entry->setTicket($issue->key);
        $oldEntry->setTicket($issue->key);
        $oldEntry->setInternalJiraTicketOriginalKey($strOdlEntryTicket);

        $this->updateJiraWorklog(
            $entry,
            $oldEntry,
            $internalJiraTicketSystem
        );
    }

    /**
     * Returns true, if the ticket should be deleted.
     */
    protected function shouldTicketBeDeleted(Entry $entry, Entry $oldEntry): bool
    {
        $bDifferentTickets
            = $oldEntry->getTicket() !== $entry->getTicket();
        $bIsCurrentTicketOriginalTicket
            = $entry->getInternalJiraTicketOriginalKey() === $entry->getTicket();

        return !$bIsCurrentTicketOriginalTicket && $bDifferentTickets;
    }
}



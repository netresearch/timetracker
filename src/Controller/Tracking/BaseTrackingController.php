<?php

declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Entity\Project;
use App\Enum\EntryClass;
use App\Enum\TicketSystemType;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Util\TicketService;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function count;
use function is_object;
use function sprintf;

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

        if (!$ticketSystem->getBookTime() || TicketSystemType::JIRA !== $ticketSystem->getType()) {
            return;
        }

        if (!$this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory || !$entry->getUser() instanceof User) {
            return;
        }

        $jiraOAuthApiService = $this->jiraOAuthApiFactory->create($entry->getUser(), $ticketSystem);
        $jiraOAuthApiService->deleteEntryJiraWorkLog($entry);
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
        $objectRepository = $objectManager->getRepository(Entry::class);
        $entries = $objectRepository->findByDay($userId, $day);

        if (0 === count($entries)) {
            return;
        }

        $normalizedEntries = [];
        foreach ($entries as $entry) {
            if ($entry instanceof Entry) {
                $normalizedEntries[] = [
                    'id' => (int) $entry->getId(),
                    'start' => $entry->getStart(),
                    'end' => $entry->getEnd(),
                ];
            }
        }

        if (0 === count($normalizedEntries)) {
            return;
        }

        // Sort by start time
        usort($normalizedEntries, static function (array $a, array $b): int {
            return $a['start'] <=> $b['start'];
        });

        // Calculate overlaps
        for ($i = 0; $i < count($normalizedEntries); $i++) {
            for ($j = $i + 1; $j < count($normalizedEntries); $j++) {
                if ($normalizedEntries[$i]['end'] > $normalizedEntries[$j]['start']) {
                    // Mark both as overlapping
                    $this->addEntryClass($normalizedEntries[$i]['id'], EntryClass::OVERLAPPING);
                    $this->addEntryClass($normalizedEntries[$j]['id'], EntryClass::OVERLAPPING);
                }
            }
        }

        // Calculate pauses and day breaks
        for ($i = 0; $i < count($normalizedEntries) - 1; $i++) {
            $current = $normalizedEntries[$i];
            $next = $normalizedEntries[$i + 1];

            $pauseMinutes = ($next['start']->getTimestamp() - $current['end']->getTimestamp()) / 60;

            if ($pauseMinutes > 0) {
                if ($pauseMinutes >= 60) {  // 1 hour or more
                    $this->addEntryClass($current['id'], EntryClass::DAYBREAK);
                } else {
                    $this->addEntryClass($current['id'], EntryClass::PAUSE);
                }
            }
        }
    }

    /**
     * Add a rendering class to an entry.
     */
    private function addEntryClass(int $entryId, EntryClass $class): void
    {
        $entry = $this->managerRegistry->getRepository(Entry::class)->find($entryId);
        if ($entry instanceof Entry) {
            $entry->addClass($class);
            $this->managerRegistry->getManager()->flush();
        }
    }

    /**
     * Creates a work log entry in a remote JIRA installation.
     * JIRA instance is defined by ticket system in project.
     *
     * @throws JiraApiException
     */
    protected function createJiraEntry(Entry $entry, User $user): void
    {
        $project = $entry->getProject();

        if (!$project instanceof Project) {
            return;
        }

        if ('' === $entry->getTicket()) {
            return;
        }

        $ticketSystem = $project->getTicketSystem();

        // Support for internal jira project and ticket system
        if ($project && $project->hasInternalJiraProjectKey()) {
            $this->validateTicketProjectMatch($entry, $project);

            /** @var \App\Repository\TicketSystemRepository $ticketSystemRepo */
            $ticketSystemRepo = $this->managerRegistry->getRepository(TicketSystem::class);
            $ticketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());
        }

        if (!$ticketSystem instanceof TicketSystem) {
            return;
        }

        if (!$ticketSystem->getBookTime() || TicketSystemType::JIRA !== $ticketSystem->getType()) {
            return;
        }

        if (!$this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory) {
            return;
        }

        try {
            $jiraOAuthApiService = $this->jiraOAuthApiFactory->create($user, $ticketSystem);
            $jiraOAuthApiService->createEntryJiraWorkLog($entry);
        } catch (Exception $exception) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('Failed to create JIRA work log', [
                    'entry_id' => $entry->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }
            throw new JiraApiException('Failed to create JIRA work log: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Validates that the ticket project matches the project JIRA ID.
     * Extracts the project key from the ticket and validates against project JIRA IDs.
     *
     * @throws Exception when ticket project doesn't match
     */
    protected function validateTicketProjectMatch(Entry $entry, Project $project): void
    {
        $ticket = $entry->getTicket();
        if ('' === $ticket) {
            return;
        }

        if (!$this->ticketService instanceof TicketService) {
            throw new RuntimeException('Ticket service not available');
        }

        $jiraId = $this->ticketService->extractJiraId($ticket);
        if ('' === $jiraId) {
            return;
        }

        $projectJiraId = $project->getJiraId();
        if ('' === $projectJiraId) {
            return;
        }

        $projectIds = explode(',', $projectJiraId);

        foreach ($projectIds as $projectId) {
            if (trim($projectId) === $jiraId || $project->matchesInternalJiraProject($jiraId)) {
                return;
            }
        }

        $message = $this->translator->trans(
            "The ticket's Jira ID '%ticket_jira_id%' does not match the project's Jira ID '%project_jira_id%'.",
            ['%ticket_jira_id%' => $jiraId, '%project_jira_id%' => $project->getJiraId()],
        );

        throw new Exception($message);
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

        if (!$ticketSystem->getBookTime() || TicketSystemType::JIRA !== $ticketSystem->getType()) {
            return;
        }

        if ($this->shouldTicketBeDeleted($entry, $oldEntry)) {
            $this->deleteJiraWorklog($oldEntry, $ticketSystem);
            $entry->setWorklogId(null);
        }

        if (!$this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory || !$entry->getUser() instanceof User) {
            return;
        }

        $jiraOAuthApiService = $this->jiraOAuthApiFactory->create($entry->getUser(), $ticketSystem);
        $jiraOAuthApiService->updateEntryJiraWorkLog($entry);
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

        $jiraOAuthApiService = $this->jiraOAuthApiFactory->create($entry->getUser(), $ticketSystem);

        return $jiraOAuthApiService->createTicket($entry);
    }

    /**
     * Handles the entry for the configured internal ticketsystem.
     */
    protected function handleInternalJiraTicketSystem(Entry $entry, Entry $oldEntry): void
    {
        $project = $entry->getProject();

        if (!$project instanceof Project || !$project->hasInternalJiraProjectKey()) {
            return;
        }

        /** @var \App\Repository\TicketSystemRepository $ticketSystemRepo */
        $ticketSystemRepo = $this->managerRegistry->getRepository(TicketSystem::class);
        $ticketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());

        if (!$ticketSystem instanceof TicketSystem) {
            return;
        }

        if ('' === $oldEntry->getTicket()) {
            $this->createJiraEntry($entry, $entry->getUser());
        } else {
            $this->updateJiraWorklog($entry, $oldEntry, $ticketSystem);
        }
    }

    /**
     * Determines whether the old entry ticket should be deleted.
     */
    protected function shouldTicketBeDeleted(Entry $entry, Entry $oldEntry): bool
    {
        // Delete if ticket is removed or changed
        return '' !== $oldEntry->getTicket() && $entry->getTicket() !== $oldEntry->getTicket();
    }

    /**
     * Gets a DateTime object from a date string or returns null.
     */
    protected function getDateTimeFromString(?string $dateString): ?DateTime
    {
        if (null === $dateString || '' === $dateString) {
            return null;
        }

        try {
            return new DateTime($dateString);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Validates entry date and time values.
     *
     * @throws Exception when validation fails
     */
    protected function validateEntryDateTime(Entry $entry): void
    {
        $start = $entry->getStart();
        $end = $entry->getEnd();

        if (!$start instanceof DateTime || !$end instanceof DateTime) {
            throw new Exception('Entry must have valid start and end times');
        }

        if ($start >= $end) {
            throw new Exception('Entry start time must be before end time');
        }

        $maxDuration = new \DateInterval('PT23H59M');
        $duration = $start->diff($end);
        
        if ($duration->days > 0 || $duration->h > 23) {
            throw new Exception('Entry duration cannot exceed 24 hours');
        }
    }

    /**
     * Calculates the duration of an entry in minutes.
     */
    protected function calculateDurationMinutes(Entry $entry): int
    {
        $start = $entry->getStart();
        $end = $entry->getEnd();

        if (!$start instanceof DateTime || !$end instanceof DateTime) {
            return 0;
        }

        return (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
    }

    /**
     * Formats duration in minutes to human readable format.
     */
    protected function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return sprintf('%dm', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($mins === 0) {
            return sprintf('%dh', $hours);
        }

        return sprintf('%dh %dm', $hours, $mins);
    }
}
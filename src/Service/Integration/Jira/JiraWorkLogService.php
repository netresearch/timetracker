<?php

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiInvalidResourceException;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use Exception;

use function sprintf;

/**
 * Manages Jira work log synchronization.
 * Handles creation, update, and deletion of work logs.
 */
class JiraWorkLogService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly JiraHttpClientService $httpClient,
        private readonly JiraTicketService $ticketService,
        private readonly JiraAuthenticationService $authService,
    ) {
    }

    /**
     * Updates all Jira work log entries for user and ticket system.
     */
    public function updateAllEntriesWorkLogs(User $user, TicketSystem $ticketSystem): void
    {
        $this->updateEntriesWorkLogsLimited($user, $ticketSystem);
    }

    /**
     * Updates limited number of Jira work log entries.
     * Entries are ordered by date and time descending.
     */
    public function updateEntriesWorkLogsLimited(
        User $user,
        TicketSystem $ticketSystem,
        ?int $entryLimit = null,
    ): void {
        if (!$this->authService->checkUserTicketSystem($user, $ticketSystem)) {
            return;
        }

        $em = $this->managerRegistry->getManager();
        $entryRepository = $this->managerRegistry->getRepository(Entry::class);

        $entries = $entryRepository->findByUserAndTicketSystemToSync(
            (int) $user->getId(),
            (int) $ticketSystem->getId(),
            $entryLimit,
        );

        foreach ($entries as $entry) {
            try {
                $this->updateEntryWorkLog($entry);
                $em->persist($entry);
            } catch (Exception $e) {
                // Log error but continue with other entries
                // This prevents one failed entry from blocking all others
                error_log(sprintf(
                    'Failed to sync work log for entry %d: %s',
                    $entry->getId(),
                    $e->getMessage(),
                ));
            } finally {
                $em->flush();
            }
        }
    }

    /**
     * Creates or updates a Jira work log entry.
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    public function updateEntryWorkLog(Entry $entry): void
    {
        $ticket = $entry->getTicket();

        // Skip entries without ticket
        if ('' === $ticket || '0' === $ticket) {
            return;
        }

        $user = $entry->getUser();
        $project = $entry->getProject();

        if (!$user || !$project) {
            return;
        }

        $ticketSystem = $project->getTicketSystem();

        if (!$ticketSystem) {
            return;
        }

        if (!$this->authService->checkUserTicketSystem($user, $ticketSystem)) {
            return;
        }

        // Verify ticket exists in Jira
        if (!$this->ticketService->doesTicketExist($ticket)) {
            return;
        }

        // Handle zero duration entries
        if (0 === $entry->getDuration()) {
            $this->deleteEntryWorkLog($entry);

            return;
        }

        // Verify existing work log ID is still valid
        if (null !== $entry->getWorklogId() && !$this->doesWorkLogExist($ticket, $entry->getWorklogId())) {
            $entry->setWorklogId(null);
        }

        // Prepare work log data
        $workLogData = $this->prepareWorkLogData($entry);

        // Create or update work log
        if ($entry->getWorklogId()) {
            $workLog = $this->updateWorkLog($ticket, $entry->getWorklogId(), $workLogData);
        } else {
            $workLog = $this->createWorkLog($ticket, $workLogData);
        }

        if (!isset($workLog->id)) {
            throw new JiraApiException('Unexpected response from Jira when updating worklog', 500);
        }

        // Update entry with work log ID
        $entry->setWorklogId((int) $workLog->id);
        $entry->setSyncedToTicketsystem(true);
    }

    /**
     * Deletes a Jira work log entry.
     *
     * @throws JiraApiException
     */
    public function deleteEntryWorkLog(Entry $entry): void
    {
        $ticket = $entry->getTicket();

        if ('' === $ticket || '0' === $ticket) {
            return;
        }

        $workLogId = $entry->getWorklogId();

        if (!$workLogId) {
            return;
        }

        $user = $entry->getUser();
        $project = $entry->getProject();

        if (!$user || !$project) {
            return;
        }

        $ticketSystem = $project->getTicketSystem();

        if (!$ticketSystem) {
            return;
        }

        if (!$this->authService->checkUserTicketSystem($user, $ticketSystem)) {
            return;
        }

        // Only delete if work log exists
        if (!$this->doesWorkLogExist($ticket, $workLogId)) {
            $entry->setWorklogId(null);

            return;
        }

        try {
            $this->httpClient->delete(sprintf('issue/%s/worklog/%d', $ticket, $workLogId));
            $entry->setWorklogId(null);
            $entry->setSyncedToTicketsystem(false);
        } catch (JiraApiInvalidResourceException) {
            // Work log already deleted in Jira
            $entry->setWorklogId(null);
            $entry->setSyncedToTicketsystem(false);
        }
    }

    /**
     * Checks if work log exists in Jira.
     */
    private function doesWorkLogExist(string $ticket, int $workLogId): bool
    {
        return $this->httpClient->doesResourceExist(
            sprintf('issue/%s/worklog/%d', $ticket, $workLogId),
        );
    }

    /**
     * Creates new work log in Jira.
     *
     * @param array<string, mixed> $data
     */
    private function createWorkLog(string $ticket, array $data): object
    {
        return $this->httpClient->post(sprintf('issue/%s/worklog', $ticket), $data);
    }

    /**
     * Updates existing work log in Jira.
     *
     * @param array<string, mixed> $data
     */
    private function updateWorkLog(string $ticket, int $workLogId, array $data): object
    {
        return $this->httpClient->put(sprintf('issue/%s/worklog/%d', $ticket, $workLogId), $data);
    }

    /**
     * Prepares work log data for Jira API.
     *
     * @return array<string, mixed>
     */
    private function prepareWorkLogData(Entry $entry): array
    {
        return [
            'comment' => $this->getWorkLogComment($entry),
            'started' => $this->getWorkLogStartDate($entry),
            'timeSpentSeconds' => $entry->getDuration() * 60,
        ];
    }

    /**
     * Generates work log comment from entry.
     */
    private function getWorkLogComment(Entry $entry): string
    {
        $customer = $entry->getCustomer();
        $project = $entry->getProject();
        $activity = $entry->getActivity();

        $description = (string) $entry->getDescription();

        if ('' === $description) {
            $description = 'no description';
        }

        $parts = [];

        if ($customer) {
            $parts[] = $customer->getName();
        }

        if ($project) {
            $parts[] = $project->getName();
        }

        if ($activity) {
            $parts[] = $activity->getName();
        }

        $parts[] = $description;

        return implode(' | ', $parts);
    }

    /**
     * Gets formatted start date for work log.
     */
    private function getWorkLogStartDate(Entry $entry): string
    {
        $day = $entry->getDay();
        $start = $entry->getStart();

        // $day and $start are non-nullable per Entity definition, so no null checks needed

        // Combine date and time
        $dateTime = new DateTime($day->format('Y-m-d') . ' ' . $start->format('H:i:s'));

        // Format for Jira API (ISO 8601)
        return $dateTime->format('Y-m-d\TH:i:s.vO');
    }

    /**
     * Sync worklog for an entry with JIRA.
     * 
     * @param User $user
     * @param TicketSystem $ticketSystem
     * @param Entry $entry
     * @param array<string, mixed> $worklogData
     * @return array{worklogId: int|null}
     */
    public function syncWorkLog(User $user, TicketSystem $ticketSystem, Entry $entry, array $worklogData): array
    {
        // Update the entry's worklog
        $this->updateEntryWorkLog($entry);
        
        // Return the worklog ID if it exists
        return [
            'worklogId' => $entry->getWorklogId(),
        ];
    }
}

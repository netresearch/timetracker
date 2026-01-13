<?php

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\TicketSystemType;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Repository\EntryRepository;
use App\Repository\TicketSystemRepository;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Psr\Log\LoggerInterface;

use function assert;
use function in_array;

/**
 * Service for managing JIRA integration operations.
 * Extracted from BaseTrackingController to improve separation of concerns.
 */
class JiraIntegrationService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly JiraWorkLogService $jiraWorkLogService,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Saves or updates a worklog entry in JIRA.
     *
     * @throws JiraApiException
     * @throws JiraApiUnauthorizedException
     */
    public function saveWorklog(Entry $entry): bool
    {
        $project = $entry->getProject();
        if (!$project instanceof Project) {
            $this->logger?->warning('No project associated with entry');

            return false;
        }

        $ticketSystem = $this->getTicketSystem($project);
        if (!$this->shouldSyncWithJira($ticketSystem, $entry)) {
            $this->logger?->debug('Entry should not sync with JIRA');

            return false;
        }

        $user = $entry->getUser();
        if (!$user instanceof User) {
            throw new JiraApiException('Entry has no associated user');
        }

        try {
            // Use the public method to update entry worklog
            $this->jiraWorkLogService->updateEntryWorkLog($entry);

            $this->logger?->info('JIRA worklog synced');

            return true;
        } catch (Exception $exception) {
            $this->logger?->error('JIRA sync failed', ['exception' => $exception]);
            throw $exception;
        }
    }

    /**
     * Deletes a worklog entry from JIRA.
     *
     * @throws JiraApiException
     */
    public function deleteWorklog(Entry $entry): bool
    {
        if (null === $entry->getWorklogId()) {
            $this->logger?->info('Entry has no worklog ID to delete');

            return false;
        }

        $project = $entry->getProject();
        if (!$project instanceof Project) {
            throw new JiraApiException('Entry has no associated project');
        }

        $ticketSystem = $this->getTicketSystem($project);
        if (!$ticketSystem instanceof TicketSystem) {
            throw new JiraApiException('Project has no ticket system configured');
        }

        $user = $entry->getUser();
        if (!$user instanceof User) {
            throw new JiraApiException('Entry has no associated user');
        }

        try {
            $this->jiraWorkLogService->deleteEntryWorkLog($entry);

            // Clear worklog reference
            $entry->setWorklogId(null);
            $entry->setSyncedToTicketsystem(false);

            $this->managerRegistry->getManager()->persist($entry);
            $this->managerRegistry->getManager()->flush();

            $this->logger?->info('JIRA worklog deleted');

            return true;
        } catch (Exception $exception) {
            $this->logger?->error('JIRA worklog deletion failed', ['exception' => $exception]);
            throw $exception;
        }
    }

    /**
     * Syncs multiple entries to JIRA in batch.
     *
     * @param Entry[] $entries
     *
     * @return array<int, bool> Entry ID => success status
     */
    public function batchSyncWorkLogs(array $entries): array
    {
        $results = [];

        foreach ($entries as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }

            try {
                $entryId = $entry->getId() ?? 0;
                $results[$entryId] = $this->saveWorklog($entry);
            } catch (Exception $e) {
                $entryId = $entry->getId() ?? 0;
                $results[$entryId] = false;
                $this->logger?->error('Batch sync failed for entry', ['exception' => $e]);
            }
        }

        return $results;
    }

    /**
     * Gets entries that need to be synced to JIRA.
     *
     * @return Entry[]
     */
    public function getEntriesNeedingSync(?User $user = null, ?DateTime $since = null): array
    {
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);

        $criteria = [
            'syncedToTicketsystem' => false,
        ];

        if ($user instanceof User) {
            $criteria['user'] = $user;
        }

        $entries = $objectRepository->findBy($criteria);

        // Filter entries that should sync with JIRA
        $syncableEntries = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }

            if ($since instanceof DateTime && $entry->getStart() < $since) {
                continue;
            }

            $project = $entry->getProject();
            if (!$project instanceof Project) {
                continue;
            }

            $ticketSystem = $this->getTicketSystem($project);
            if ($this->shouldSyncWithJira($ticketSystem, $entry)) {
                $syncableEntries[] = $entry;
            }
        }

        return $syncableEntries;
    }

    /**
     * Determines the appropriate ticket system for a project.
     */
    private function getTicketSystem(Project $project): ?TicketSystem
    {
        // Check for internal JIRA project configuration
        if ($project->hasInternalJiraProjectKey()) {
            $ticketSystemRepo = $this->managerRegistry->getRepository(TicketSystem::class);
            assert($ticketSystemRepo instanceof TicketSystemRepository);
            $internalTicketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());

            if ($internalTicketSystem instanceof TicketSystem) {
                return $internalTicketSystem;
            }
        }

        // Fall back to project's default ticket system
        return $project->getTicketSystem();
    }

    /**
     * Determines if an entry should be synchronized with JIRA.
     */
    private function shouldSyncWithJira(?TicketSystem $ticketSystem, Entry $entry): bool
    {
        if (!$ticketSystem instanceof TicketSystem) {
            return false;
        }

        if (!$ticketSystem->getBookTime() || TicketSystemType::JIRA !== $ticketSystem->getType()) {
            return false;
        }

        // Entry must have a ticket number
        if (in_array($entry->getTicket(), ['', '0'], true)) {
            return false;
        }

        // Entry must have valid time data
        if (!$entry->getStart() instanceof DateTime || !$entry->getEnd() instanceof DateTime) {
            return false;
        }

        // Calculate duration - must be greater than 0
        $duration = $entry->getEnd()->getTimestamp() - $entry->getStart()->getTimestamp();

        return $duration > 0;
    }

    /**
     * Validates JIRA connection for a ticket system.
     */
    public function validateJiraConnection(TicketSystem $ticketSystem, User $user): bool
    {
        if (TicketSystemType::JIRA !== $ticketSystem->getType()) {
            return false;
        }

        try {
            return $this->jiraWorkLogService->validateConnection($user, $ticketSystem);
        } catch (Exception $exception) {
            $this->logger?->error('JIRA connection validation failed', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Gets JIRA project information.
     *
     * @return array<string, mixed>|null
     */
    public function getJiraProjectInfo(string $projectKey, TicketSystem $ticketSystem, User $user): ?array
    {
        if (TicketSystemType::JIRA !== $ticketSystem->getType()) {
            return null;
        }

        try {
            return $this->jiraWorkLogService->getProjectInfo($projectKey, $user, $ticketSystem);
        } catch (Exception $exception) {
            $this->logger?->error('Failed to get JIRA project info', ['exception' => $exception]);

            return null;
        }
    }
}

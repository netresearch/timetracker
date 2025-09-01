<?php

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Repository\TicketSystemRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Service for managing JIRA integration operations.
 * Extracted from BaseTrackingController to improve separation of concerns.
 */
class JiraIntegrationService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly JiraOAuthApiFactory $jiraApiFactory,
        private readonly JiraWorkLogService $workLogService,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Deletes a work log entry in JIRA if applicable.
     * 
     * @throws JiraApiException
     * @throws JiraApiUnauthorizedException
     */
    public function deleteWorklog(Entry $entry, ?TicketSystem $ticketSystem = null): void
    {
        $ticketSystem = $this->resolveTicketSystem($entry, $ticketSystem);
        
        if (!$this->shouldSyncWithJira($ticketSystem, $entry)) {
            $this->log('Skipping JIRA worklog deletion - sync not required', [
                'entry_id' => $entry->getId(),
                'ticket_system' => $ticketSystem?->getId(),
            ]);
            return;
        }

        $user = $entry->getUser();
        if (!$user instanceof User) {
            $this->log('Cannot delete JIRA worklog - no user associated with entry', [
                'entry_id' => $entry->getId(),
            ]);
            return;
        }

        try {
            $api = $this->jiraApiFactory->createApiObject($user, $ticketSystem);
            
            if ($entry->getWorklogId()) {
                $api->deleteWorkLog($entry->getTicket(), $entry->getWorklogId());
                $this->log('JIRA worklog deleted successfully', [
                    'entry_id' => $entry->getId(),
                    'worklog_id' => $entry->getWorklogId(),
                    'ticket' => $entry->getTicket(),
                ]);
            }
        } catch (JiraApiException $e) {
            $this->log('Failed to delete JIRA worklog', [
                'entry_id' => $entry->getId(),
                'error' => $e->getMessage(),
            ], 'error');
            throw $e;
        }
    }

    /**
     * Saves or updates a work log entry in JIRA if applicable.
     * 
     * @throws JiraApiException
     * @throws JiraApiUnauthorizedException
     */
    public function saveWorklog(Entry $entry, ?TicketSystem $ticketSystem = null): void
    {
        $ticketSystem = $this->resolveTicketSystem($entry, $ticketSystem);
        
        if (!$this->shouldSyncWithJira($ticketSystem, $entry)) {
            $this->log('Skipping JIRA worklog save - sync not required', [
                'entry_id' => $entry->getId(),
                'ticket_system' => $ticketSystem?->getId(),
            ]);
            return;
        }

        $user = $entry->getUser();
        if (!$user instanceof User) {
            $this->log('Cannot save JIRA worklog - no user associated with entry', [
                'entry_id' => $entry->getId(),
            ]);
            return;
        }

        try {
            $worklogData = $this->prepareWorklogData($entry);
            $result = $this->workLogService->syncWorkLog($user, $ticketSystem, $entry, $worklogData);
            
            if ($result['worklogId']) {
                $entry->setWorklogId($result['worklogId']);
                $entry->setSyncedToTicketsystem(true);
                
                $em = $this->managerRegistry->getManager();
                $em->persist($entry);
                $em->flush();
                
                $this->log('JIRA worklog saved successfully', [
                    'entry_id' => $entry->getId(),
                    'worklog_id' => $result['worklogId'],
                    'ticket' => $entry->getTicket(),
                ]);
            }
        } catch (JiraApiException $e) {
            $this->log('Failed to save JIRA worklog', [
                'entry_id' => $entry->getId(),
                'error' => $e->getMessage(),
            ], 'error');
            throw $e;
        }
    }

    /**
     * Bulk syncs multiple entries with JIRA.
     * 
     * @param Entry[] $entries
     * @return array Results with success/failure for each entry
     */
    public function bulkSyncEntries(array $entries, ?TicketSystem $ticketSystem = null): array
    {
        $results = [];
        
        foreach ($entries as $entry) {
            try {
                $this->saveWorklog($entry, $ticketSystem);
                $results[$entry->getId()] = [
                    'success' => true,
                    'message' => 'Synced successfully',
                ];
            } catch (\Exception $e) {
                $results[$entry->getId()] = [
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }
        
        return $results;
    }

    /**
     * Checks if an entry needs JIRA synchronization.
     */
    public function needsSync(Entry $entry): bool
    {
        if ($entry->getSyncedToTicketsystem()) {
            return false;
        }
        
        $ticketSystem = $this->resolveTicketSystem($entry);
        
        return $this->shouldSyncWithJira($ticketSystem, $entry);
    }

    /**
     * Resolves the ticket system for an entry.
     */
    private function resolveTicketSystem(Entry $entry, ?TicketSystem $ticketSystem = null): ?TicketSystem
    {
        if ($ticketSystem instanceof TicketSystem) {
            return $ticketSystem;
        }
        
        $project = $entry->getProject();
        if (!$project instanceof Project) {
            return null;
        }
        
        // Check for internal JIRA project configuration
        if ($project->hasInternalJiraProjectKey()) {
            /** @var TicketSystemRepository $ticketSystemRepo */
            $ticketSystemRepo = $this->managerRegistry->getRepository(TicketSystem::class);
            return $ticketSystemRepo->find($project->getInternalJiraTicketSystem());
        }
        
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
        
        if (!$ticketSystem->getBookTime() || 'JIRA' !== $ticketSystem->getType()) {
            return false;
        }
        
        if (empty($entry->getTicket())) {
            return false;
        }
        
        // Don't sync if duration is 0
        if ($entry->getDuration() <= 0) {
            return false;
        }
        
        return true;
    }

    /**
     * Prepares worklog data for JIRA API.
     */
    private function prepareWorklogData(Entry $entry): array
    {
        $startTime = $entry->getStart();
        $day = $entry->getDay();
        
        // Combine date and time for JIRA timestamp
        $timestamp = new \DateTime($day->format('Y-m-d') . ' ' . $startTime->format('H:i:s'));
        
        return [
            'ticket' => $entry->getTicket(),
            'comment' => $entry->getDescription() ?: 'Time tracked via Timetracker',
            'timeSpentSeconds' => $entry->getDuration() * 60, // Convert minutes to seconds
            'started' => $timestamp->format('Y-m-d\TH:i:s.000O'),
            'worklogId' => $entry->getWorklogId(),
        ];
    }

    /**
     * Logs messages with context.
     */
    private function log(string $message, array $context = [], string $level = 'info'): void
    {
        if (!$this->logger) {
            return;
        }
        
        $context['service'] = 'JiraIntegrationService';
        
        match ($level) {
            'error' => $this->logger->error($message, $context),
            'warning' => $this->logger->warning($message, $context),
            'debug' => $this->logger->debug($message, $context),
            default => $this->logger->info($message, $context),
        };
    }
}
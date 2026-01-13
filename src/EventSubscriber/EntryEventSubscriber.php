<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Entry;
use App\Enum\TicketSystemType;
use App\Event\EntryEvent;
use App\Service\Cache\QueryCacheService;
use App\Service\Integration\Jira\JiraIntegrationService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

use function in_array;

/**
 * Handles entry-related events.
 */
class EntryEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly JiraIntegrationService $jiraIntegrationService,
        private readonly QueryCacheService $queryCacheService,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntryEvent::CREATED => 'onEntryCreated',
            EntryEvent::UPDATED => 'onEntryUpdated',
            EntryEvent::DELETED => 'onEntryDeleted',
            EntryEvent::SYNCED => 'onEntrySynced',
            EntryEvent::SYNC_FAILED => 'onEntrySyncFailed',
        ];
    }

    public function onEntryCreated(EntryEvent $entryEvent): void
    {
        $entry = $entryEvent->getEntry();

        $this->logger?->info('Entry created');

        // Invalidate cache for user entries
        $user = $entry->getUser();
        $userId = $user?->getId();
        if (null !== $userId) {
            $this->queryCacheService->invalidateEntity(
                Entry::class,
                $userId,
            );
        }

        // Check if automatic JIRA sync is needed
        if ($this->shouldAutoSync($entry)) {
            try {
                $this->jiraIntegrationService->saveWorklog($entry);
                $this->logger?->info('Entry auto-synced to JIRA');
            } catch (Exception $e) {
                $this->logger?->error('Auto-sync to JIRA failed', ['exception' => $e]);
            }
        }
    }

    public function onEntryUpdated(EntryEvent $entryEvent): void
    {
        $entry = $entryEvent->getEntry();

        $this->logger?->info('Entry updated');

        // Invalidate cache
        $user = $entry->getUser();
        $userId = $user?->getId();
        if (null !== $userId) {
            $this->queryCacheService->invalidateEntity(
                Entry::class,
                $userId,
            );
        }

        // Update JIRA if already synced
        if ($entry->getSyncedToTicketsystem() && null !== $entry->getWorklogId()) {
            try {
                $this->jiraIntegrationService->saveWorklog($entry);
                $this->logger?->info('JIRA worklog updated');
            } catch (Exception $e) {
                $this->logger?->warning('JIRA worklog update failed', ['exception' => $e]);
            }
        }
    }

    public function onEntryDeleted(EntryEvent $entryEvent): void
    {
        $entry = $entryEvent->getEntry();

        $this->logger?->info('Entry deleted');

        // Invalidate cache
        $user = $entry->getUser();
        $userId = $user?->getId();
        if (null !== $userId) {
            $this->queryCacheService->invalidateEntity(
                Entry::class,
                $userId,
            );
        }

        // Delete from JIRA if synced
        if ($entry->getSyncedToTicketsystem() && null !== $entry->getWorklogId()) {
            try {
                $this->jiraIntegrationService->deleteWorklog($entry);
                $this->logger?->info('JIRA worklog deleted');
            } catch (Exception $e) {
                $this->logger?->warning('JIRA worklog deletion failed', ['exception' => $e]);
            }
        }
    }

    public function onEntrySynced(EntryEvent $entryEvent): void
    {
        $this->logger?->info('Entry synced to JIRA');

        // Clear sync-related cache
        $this->queryCacheService->invalidateTag('jira_sync');
    }

    public function onEntrySyncFailed(EntryEvent $entryEvent): void
    {
        $context = $entryEvent->getContext();
        $exception = $context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $this->logger?->error('Entry sync to JIRA failed', ['exception' => $exception]);
        } else {
            $this->logger?->error('Entry sync to JIRA failed');
        }

        // Could trigger notification or retry logic here
    }

    private function shouldAutoSync(Entry $entry): bool
    {
        // Check if project has auto-sync enabled
        $project = $entry->getProject();
        if (!$project instanceof \App\Entity\Project) {
            return false;
        }

        $ticketSystem = $project->getTicketSystem();
        if (!$ticketSystem instanceof \App\Entity\TicketSystem) {
            return false;
        }

        // Check if ticket system has auto-sync enabled
        return $ticketSystem->getBookTime()
               && TicketSystemType::JIRA === $ticketSystem->getType()
               && !in_array($entry->getTicket(), ['', '0'], true);
    }
}

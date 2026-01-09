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

        $this->log('Entry created', [
            'entry_id' => $entry->getId(),
            'user_id' => $entry->getUser()?->getId(),
        ]);

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
                $this->log('Entry auto-synced to JIRA', ['entry_id' => $entry->getId()]);
            } catch (Exception $e) {
                $this->log('Auto-sync failed', [
                    'entry_id' => $entry->getId(),
                    'error' => $e->getMessage(),
                ], 'error');
            }
        }
    }

    public function onEntryUpdated(EntryEvent $entryEvent): void
    {
        $entry = $entryEvent->getEntry();

        $this->log('Entry updated', [
            'entry_id' => $entry->getId(),
            'changes' => $entryEvent->getContext()['changes'] ?? [],
        ]);

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
                $this->log('JIRA worklog updated', ['entry_id' => $entry->getId()]);
            } catch (Exception $e) {
                $this->log('JIRA update failed', [
                    'entry_id' => $entry->getId(),
                    'error' => $e->getMessage(),
                ], 'warning');
            }
        }
    }

    public function onEntryDeleted(EntryEvent $entryEvent): void
    {
        $entry = $entryEvent->getEntry();

        $this->log('Entry deleted', ['entry_id' => $entry->getId()]);

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
                $this->log('JIRA worklog deleted', ['entry_id' => $entry->getId()]);
            } catch (Exception $e) {
                $this->log('JIRA deletion failed', [
                    'entry_id' => $entry->getId(),
                    'error' => $e->getMessage(),
                ], 'warning');
            }
        }
    }

    public function onEntrySynced(EntryEvent $entryEvent): void
    {
        $entry = $entryEvent->getEntry();

        $this->log('Entry synced to JIRA', [
            'entry_id' => $entry->getId(),
            'worklog_id' => $entry->getWorklogId(),
        ]);

        // Clear sync-related cache
        $this->queryCacheService->invalidateTag('jira_sync');
    }

    public function onEntrySyncFailed(EntryEvent $entryEvent): void
    {
        $entry = $entryEvent->getEntry();
        $context = $entryEvent->getContext();

        $this->log('Entry sync failed', [
            'entry_id' => $entry->getId(),
            'error' => $context['error'] ?? 'Unknown error',
        ], 'error');

        // Could trigger notification or retry logic here
    }

    private function shouldAutoSync(Entry $entry): bool
    {
        // Check if project has auto-sync enabled
        $project = $entry->getProject();
        if (! $project instanceof \App\Entity\Project) {
            return false;
        }

        $ticketSystem = $project->getTicketSystem();
        if (! $ticketSystem instanceof \App\Entity\TicketSystem) {
            return false;
        }

        // Check if ticket system has auto-sync enabled
        return $ticketSystem->getBookTime()
               && TicketSystemType::JIRA === $ticketSystem->getType()
               && ! in_array($entry->getTicket(), ['', '0'], true);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $message, array $context = [], string $level = 'info'): void
    {
        if (! $this->logger instanceof LoggerInterface) {
            return;
        }

        $context['subscriber'] = 'EntryEventSubscriber';

        match ($level) {
            'error' => $this->logger->error($message, $context),
            'warning' => $this->logger->warning($message, $context),
            default => $this->logger->info($message, $context),
        };
    }
}

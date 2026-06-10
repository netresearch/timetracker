<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\TicketSystemType;
use App\Event\EntryEvent;
use App\Service\Cache\QueryCacheService;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

use function in_array;

/**
 * Handles entry-related events.
 *
 * Worklog synchronization deliberately uses the legacy JiraOAuthApiService
 * (via JiraOAuthApiFactory): it is the only Jira integration path wired into
 * the service container; the newer JiraIntegrationService stack stays
 * excluded until token encryption is production-ready.
 */
class EntryEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly JiraOAuthApiFactory $jiraOAuthApiFactory,
        private readonly ManagerRegistry $managerRegistry,
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

        $this->invalidateUserEntryCache($entry);

        // Check if automatic JIRA sync is needed
        if ($this->shouldAutoSync($entry)) {
            try {
                $this->syncWorklog($entry);
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

        $this->invalidateUserEntryCache($entry);

        // Sync on every update (v4 parity): updateEntryJiraWorkLog creates a
        // new worklog or updates the existing one based on the worklog id,
        // so entries that were never synced are caught up on their next save.
        if ($this->shouldAutoSync($entry)) {
            try {
                $this->syncWorklog($entry);
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

        $this->invalidateUserEntryCache($entry);

        // Delete from JIRA if synced
        if ($entry->getSyncedToTicketsystem() && null !== $entry->getWorklogId()) {
            try {
                $this->deleteWorklog($entry);
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

    private function invalidateUserEntryCache(Entry $entry): void
    {
        $user = $entry->getUser();
        $userId = $user?->getId();
        if (null !== $userId) {
            $this->queryCacheService->invalidateEntity(
                Entry::class,
                $userId,
            );
        }
    }

    private function syncWorklog(Entry $entry): void
    {
        $user = $entry->getUser();
        $ticketSystem = $entry->getProject()?->getTicketSystem();
        if (!$user instanceof User || !$ticketSystem instanceof TicketSystem) {
            return;
        }

        $this->jiraOAuthApiFactory->create($user, $ticketSystem)
            ->updateEntryJiraWorkLog($entry);

        // updateEntryJiraWorkLog sets the worklog id and the synced flag on
        // the already-persisted entity; flush them or the next sync would
        // create a duplicate worklog.
        $this->managerRegistry->getManager()->flush();
    }

    private function deleteWorklog(Entry $entry): void
    {
        $user = $entry->getUser();
        $ticketSystem = $entry->getProject()?->getTicketSystem();
        if (!$user instanceof User || !$ticketSystem instanceof TicketSystem) {
            return;
        }

        $this->jiraOAuthApiFactory->create($user, $ticketSystem)
            ->deleteEntryJiraWorkLog($entry);

        $this->managerRegistry->getManager()->flush();
    }

    private function shouldAutoSync(Entry $entry): bool
    {
        // Check if project has auto-sync enabled
        $project = $entry->getProject();
        if (!$project instanceof Project) {
            return false;
        }

        $ticketSystem = $project->getTicketSystem();
        if (!$ticketSystem instanceof TicketSystem) {
            return false;
        }

        // The Jira client acts on behalf of the entry's user
        if (!$entry->getUser() instanceof User) {
            return false;
        }

        // Check if ticket system has auto-sync enabled
        return $ticketSystem->getBookTime()
               && TicketSystemType::JIRA === $ticketSystem->getType()
               && !in_array($entry->getTicket(), ['', '0'], true);
    }
}

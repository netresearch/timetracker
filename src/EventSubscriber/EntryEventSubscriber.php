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

use function count;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Handles entry-related events.
 *
 * Worklog synchronization deliberately uses the legacy JiraOAuthApiService
 * (via JiraOAuthApiFactory): it is the only Jira integration path wired into
 * the service container; the newer JiraIntegrationService stack stays
 * excluded until token encryption is production-ready.
 *
 * Projects with an internal Jira project key get the v4 "internal ticket
 * system" behavior: time booked on an external ticket is mirrored to an
 * issue in the internal Jira (found by summary, created on demand), the
 * entry's ticket is rewritten to the internal issue key and the external
 * key is preserved in internalJiraTicketOriginalKey ("ext. ticket").
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
                $this->syncWorklog($entry, $this->getPreviousEntry($entryEvent));
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
                $this->syncWorklog($entry, $this->getPreviousEntry($entryEvent));
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

    /**
     * The pre-mutation snapshot of an updated entry, if the dispatcher
     * provided one (used for v4-parity worklog cleanup on ticket changes).
     */
    private function getPreviousEntry(EntryEvent $entryEvent): ?Entry
    {
        $previous = $entryEvent->getContext()['previous'] ?? null;

        return $previous instanceof Entry ? $previous : null;
    }

    private function syncWorklog(Entry $entry, ?Entry $previousEntry = null): void
    {
        $user = $entry->getUser();
        $project = $entry->getProject();
        if (!$user instanceof User || !$project instanceof Project) {
            return;
        }

        // v4 "internal ticket system": mirror the external ticket into the
        // internal Jira and book the worklog there.
        if ($project->hasInternalJiraProjectKey()) {
            $internalTicketSystem = $this->findInternalTicketSystem($project);
            if ($internalTicketSystem instanceof TicketSystem && $this->canBookOn($internalTicketSystem)) {
                $this->remapEntryToInternalTicket($entry, $project, $user, $internalTicketSystem);
                $this->bookWorklog($entry, null, $user, $internalTicketSystem);
            }
        }

        // Regular path: the project's own ticket system decides via its
        // book_time/type flags (v4 ran both paths; for internal-key projects
        // the own system usually has booking disabled).
        $ownTicketSystem = $project->getTicketSystem();
        if ($ownTicketSystem instanceof TicketSystem && $this->canBookOn($ownTicketSystem)) {
            $this->bookWorklog($entry, $previousEntry, $user, $ownTicketSystem);
        }
    }

    private function deleteWorklog(Entry $entry): void
    {
        $user = $entry->getUser();
        $project = $entry->getProject();
        if (!$user instanceof User || !$project instanceof Project) {
            return;
        }

        // v4 parity: entries of internal-key projects have their worklog in
        // the internal Jira, not in the project's own ticket system.
        $ticketSystem = $project->hasInternalJiraProjectKey()
            ? $this->findInternalTicketSystem($project)
            : $project->getTicketSystem();

        if (!$ticketSystem instanceof TicketSystem || !$this->canBookOn($ticketSystem)) {
            return;
        }

        $this->jiraOAuthApiFactory->create($user, $ticketSystem)
            ->deleteEntryJiraWorkLog($entry);

        $this->managerRegistry->getManager()->flush();
    }

    private function shouldAutoSync(Entry $entry): bool
    {
        $project = $entry->getProject();
        if (!$project instanceof Project) {
            return false;
        }

        // The Jira client acts on behalf of the entry's user
        if (!$entry->getUser() instanceof User) {
            return false;
        }

        if (in_array($entry->getTicket(), ['', '0'], true)) {
            return false;
        }

        // At least one bookable target must exist; the per-system gates in
        // syncWorklog() make the final call.
        return $project->getTicketSystem() instanceof TicketSystem
            || $project->hasInternalJiraProjectKey();
    }

    private function canBookOn(TicketSystem $ticketSystem): bool
    {
        return $ticketSystem->getBookTime()
            && TicketSystemType::JIRA === $ticketSystem->getType();
    }

    private function findInternalTicketSystem(Project $project): ?TicketSystem
    {
        $internalTicketSystemId = (int) $project->getInternalJiraTicketSystem();
        if (0 === $internalTicketSystemId) {
            return null;
        }

        $ticketSystem = $this->managerRegistry
            ->getRepository(TicketSystem::class)
            ->find($internalTicketSystemId);

        return $ticketSystem instanceof TicketSystem ? $ticketSystem : null;
    }

    /**
     * Finds (by summary) or creates the mirror issue in the internal Jira
     * project and rewrites the entry to it, keeping the external ticket in
     * internalJiraTicketOriginalKey (v4: CrudController::handleInternalJiraTicketSystem).
     */
    private function remapEntryToInternalTicket(
        Entry $entry,
        Project $project,
        User $user,
        TicketSystem $internalTicketSystem,
    ): void {
        // when an already-mirrored entry is edited, the internal issue must
        // be looked up by the original external key
        $externalTicket = $entry->hasInternalJiraTicketOriginalKey()
            ? (string) $entry->getInternalJiraTicketOriginalKey()
            : $entry->getTicket();

        $api = $this->jiraOAuthApiFactory->create($user, $internalTicketSystem);

        $searchResult = $api->searchTicket(
            sprintf(
                'project = %s AND summary ~ %s',
                $project->getInternalJiraProjectKey() ?? '',
                $externalTicket,
            ),
            ['key', 'summary'],
            1,
        );

        $issues = is_object($searchResult) && property_exists($searchResult, 'issues') && is_array($searchResult->issues)
            ? $searchResult->issues
            : [];

        if (count($issues) > 0) {
            $issue = reset($issues);
        } else {
            // issue does not exist in the internal Jira, create it
            $issue = $api->createTicket($entry);
        }

        if (!is_object($issue) || !property_exists($issue, 'key') || !is_string($issue->key) || '' === $issue->key) {
            throw new Exception('Unexpected response from Jira when resolving the internal ticket');
        }

        $entry->setInternalJiraTicketOriginalKey($externalTicket);
        $entry->setTicket($issue->key);

        $this->managerRegistry->getManager()->flush();
    }

    private function bookWorklog(Entry $entry, ?Entry $previousEntry, User $user, TicketSystem $ticketSystem): void
    {
        if (in_array($entry->getTicket(), ['', '0'], true)) {
            return;
        }

        $api = $this->jiraOAuthApiFactory->create($user, $ticketSystem);

        // v4 parity (CrudController::shouldTicketBeDeleted): when the ticket
        // changed, the worklog on the previous ticket is removed; a new one
        // is created below.
        if ($previousEntry instanceof Entry
            && $previousEntry->getTicket() !== $entry->getTicket()
            && $entry->getInternalJiraTicketOriginalKey() !== $entry->getTicket()
            && null !== $previousEntry->getWorklogId()
        ) {
            $api->deleteEntryJiraWorkLog($previousEntry);
            $entry->setWorklogId(null);
        }

        $api->updateEntryJiraWorkLog($entry);

        // updateEntryJiraWorkLog sets the worklog id and the synced flag on
        // the already-persisted entity; flush them or the next sync would
        // create a duplicate worklog.
        $this->managerRegistry->getManager()->flush();
    }
}

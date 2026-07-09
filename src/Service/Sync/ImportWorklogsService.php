<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\EntryClass;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Enum\WorklogSyncStatus;
use App\Repository\EntryRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Tracking\DayClassService;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Throwable;

use function array_key_exists;
use function in_array;
use function spl_object_id;
use function sprintf;
use function substr;

/**
 * ADR-023 Phase 2: imports Jira worklogs as pre-synced TT entries. Never dispatches
 * EntryEvent — imported entries must not echo back to Jira as new worklogs.
 */
class ImportWorklogsService extends AbstractSyncRunService
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly EntryRepository $entryRepository,
        private readonly JiraOAuthApiFactory $jiraOAuthApiFactory,
        private readonly RemoteWorklogNormalizer $remoteWorklogNormalizer,
        private readonly TicketProjectResolver $ticketProjectResolver,
        private readonly JiraAuthorMapper $jiraAuthorMapper,
        private readonly DayClassService $dayClassService,
        ClockInterface $clock,
    ) {
        parent::__construct($entityManager, $clock);
    }

    /**
     * @param list<string> $targetUsernames empty = import for all mapped/creatable authors
     */
    public function import(
        User $triggeredBy,
        TicketSystem $ticketSystem,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $defaultActivityId,
        array $targetUsernames = [],
        bool $dryRun = false,
    ): SyncRun {
        $syncRun = new SyncRun()
            ->setType(SyncRunType::IMPORT)
            ->setStatus(SyncRunStatus::RUNNING)
            ->setTicketSystem($ticketSystem)
            ->setTriggeredBy($triggeredBy)
            ->setScope([
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'dry_run' => $dryRun,
                'default_activity_id' => $defaultActivityId,
                'users' => $targetUsernames,
            ])
            ->setCounters([])
            ->setStartedAt($this->now());

        return $this->executeRun(
            $syncRun,
            function () use ($syncRun, $triggeredBy, $ticketSystem, $from, $to, $defaultActivityId, $targetUsernames, $dryRun): void {
                $this->run($syncRun, $triggeredBy, $ticketSystem, $from, $to, $defaultActivityId, $targetUsernames, $dryRun);
            },
        );
    }

    /**
     * @param list<string> $targetUsernames
     */
    private function run(
        SyncRun $syncRun,
        User $triggeredBy,
        TicketSystem $ticketSystem,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $defaultActivityId,
        array $targetUsernames,
        bool $dryRun,
    ): void {
        $activity = $this->entityManager->find(Activity::class, $defaultActivityId);
        if (!$activity instanceof Activity) {
            throw new InvalidArgumentException('Unknown default activity id: ' . $defaultActivityId);
        }

        $api = $this->jiraOAuthApiFactory->create($triggeredBy, $ticketSystem);

        $jql = sprintf('worklogDate >= "%s" AND worklogDate <= "%s"', $from->format('Y-m-d'), $to->format('Y-m-d'));
        $searchResult = $api->searchIssueKeysWithWorklogs($jql);
        if ($searchResult->truncated) {
            $this->addItem($syncRun, SyncItemKind::TRUNCATED, reason: 'issue search hit its result cap; import may be incomplete — narrow the date range and re-run');
        }

        $importRunContext = new ImportRunContext(
            syncRun: $syncRun,
            ticketSystem: $ticketSystem,
            activity: $activity,
            targetUsernames: $targetUsernames,
            dryRun: $dryRun,
            rangeFrom: $from->setTime(0, 0)->getTimestamp(),
            rangeTo: $to->setTime(23, 59, 59)->getTimestamp(),
        );

        foreach ($searchResult->keys as $issueKey) {
            try {
                $issueWorklogs = $api->getIssueWorklogs($issueKey);
            } catch (Throwable $throwable) {
                $syncRun->incrementCounter('errors');
                $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, reason: substr('worklog fetch failed: ' . $throwable->getMessage(), 0, 255));
                continue;
            }

            foreach ($issueWorklogs as $jiraWorkLog) {
                $this->processWorklog($importRunContext, $issueKey, $jiraWorkLog);
            }
        }

        $this->entityManager->flush();

        // Ids exist only after the flush above — freshly created shadow users have none before.
        foreach ($importRunContext->affectedDays as $affected) {
            $this->dayClassService->recalculate((int) $affected['user']->getId(), $affected['day']);
        }
    }

    private function processWorklog(ImportRunContext $importRunContext, string $issueKey, JiraWorkLog $jiraWorkLog): void
    {
        $syncRun = $importRunContext->syncRun;

        if (null === $jiraWorkLog->id) {
            return;
        }

        try {
            $snapshot = $this->remoteWorklogNormalizer->normalize($jiraWorkLog, $issueKey);
        } catch (InvalidArgumentException $exception) {
            $syncRun->incrementCounter('errors');
            $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $jiraWorkLog->id, reason: substr($exception->getMessage(), 0, 255));

            return;
        }

        if ($snapshot->startedTimestamp < $importRunContext->rangeFrom || $snapshot->startedTimestamp > $importRunContext->rangeTo) {
            return;
        }

        if ($snapshot->durationMinutes <= 0) {
            $syncRun->incrementCounter('skipped_zero_duration');

            return;
        }

        if ($this->entryRepository->findOneByWorklogIdAndTicketSystem($jiraWorkLog->id, $importRunContext->ticketSystem) instanceof Entry) {
            $syncRun->incrementCounter('already_linked');

            return;
        }

        $user = $this->resolveAuthor($importRunContext, $issueKey, $jiraWorkLog);
        if (!$user instanceof User) {
            return;
        }

        $project = $this->resolveProject($importRunContext, $issueKey);
        if (!$project instanceof Project) {
            return;
        }

        $times = $this->buildTimes($snapshot);
        if (null === $times) {
            $syncRun->incrementCounter('errors');
            $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $jiraWorkLog->id, reason: 'worklog crosses midnight; import manually');

            return;
        }

        $duplicate = $this->entryRepository->findUnlinkedDuplicate($user, $issueKey, $times['day'], $snapshot->durationMinutes);
        if ($duplicate instanceof Entry) {
            $syncRun->incrementCounter('probable_duplicate');
            $this->addItem(
                $syncRun,
                SyncItemKind::PROBABLE_DUPLICATE,
                issueKey: $issueKey,
                remoteWorklogId: $jiraWorkLog->id,
                entry: $duplicate,
                author: $this->jiraAuthorMapper->remoteKey($jiraWorkLog),
                reason: sprintf('unlinked entry %d matches user+ticket+day+duration', (int) $duplicate->getId()),
                payload: ['remote' => $snapshot->toArray(), 'updated' => $jiraWorkLog->updated],
            );

            return;
        }

        if ($importRunContext->dryRun) {
            $syncRun->incrementCounter('would_create');

            return;
        }

        $this->createEntry($importRunContext, $user, $project, $jiraWorkLog, $snapshot, $times);
    }

    /**
     * Maps the worklog author to a TT user; handles the target filter, dry-run
     * accounting and shadow creation. Null = this worklog is not imported.
     */
    private function resolveAuthor(ImportRunContext $importRunContext, string $issueKey, JiraWorkLog $jiraWorkLog): ?User
    {
        $syncRun = $importRunContext->syncRun;

        $remoteKey = $this->jiraAuthorMapper->remoteKey($jiraWorkLog);
        if (null === $remoteKey) {
            $syncRun->incrementCounter('errors');
            $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $jiraWorkLog->id, reason: 'worklog has no author identity');

            return null;
        }

        if (!array_key_exists($remoteKey, $importRunContext->authorCache)) {
            $importRunContext->authorCache[$remoteKey] = $this->jiraAuthorMapper->find($jiraWorkLog, $importRunContext->ticketSystem);
        }

        $user = $importRunContext->authorCache[$remoteKey];

        if ([] !== $importRunContext->targetUsernames && (!$user instanceof User || !in_array($user->getUsername(), $importRunContext->targetUsernames, true))) {
            $syncRun->incrementCounter('skipped_author');

            return null;
        }

        if ($user instanceof User) {
            return $user;
        }

        if (!isset($importRunContext->shadowAnnounced[$remoteKey])) {
            $importRunContext->shadowAnnounced[$remoteKey] = true;
            $this->addItem(
                $syncRun,
                SyncItemKind::SHADOW_USER_CREATED,
                issueKey: $issueKey,
                author: $remoteKey,
                reason: ($importRunContext->dryRun ? 'dry-run: would create shadow user for ' : 'created shadow user for ') . $remoteKey,
            );
            $syncRun->incrementCounter('shadow_users_created');
        }

        if ($importRunContext->dryRun) {
            $syncRun->incrementCounter('would_create');

            return null;
        }

        $user = $this->jiraAuthorMapper->createShadow($jiraWorkLog, $importRunContext->ticketSystem);
        $importRunContext->authorCache[$remoteKey] = $user;

        return $user;
    }

    private function resolveProject(ImportRunContext $importRunContext, string $issueKey): ?Project
    {
        $resolution = $this->ticketProjectResolver->resolve($issueKey, $importRunContext->ticketSystem);
        if ($resolution->project instanceof Project) {
            return $resolution->project;
        }

        $importRunContext->syncRun->incrementCounter('unresolved_project');
        if (!isset($importRunContext->unresolvedAnnounced[$issueKey])) {
            $importRunContext->unresolvedAnnounced[$issueKey] = true;
            $this->addItem($importRunContext->syncRun, SyncItemKind::UNRESOLVED_PROJECT, issueKey: $issueKey, reason: substr($resolution->reason, 0, 255));
        }

        return null;
    }

    /**
     * @return array{day: DateTime, start: DateTime, end: DateTime}|null null when the worklog crosses midnight
     */
    private function buildTimes(WorklogSnapshot $worklogSnapshot): ?array
    {
        $day = new DateTime()->setTimestamp($worklogSnapshot->startedTimestamp);
        $start = clone $day;
        $end = (clone $start)->modify(sprintf('+%d minutes', $worklogSnapshot->durationMinutes));

        if ($end->format('Y-m-d') !== $day->format('Y-m-d')) {
            return null;
        }

        return ['day' => $day, 'start' => $start, 'end' => $end];
    }

    /**
     * @param array{day: DateTime, start: DateTime, end: DateTime} $times
     */
    private function createEntry(
        ImportRunContext $importRunContext,
        User $user,
        Project $project,
        JiraWorkLog $jiraWorkLog,
        WorklogSnapshot $worklogSnapshot,
        array $times,
    ): void {
        $entry = new Entry();
        $entry->setUser($user)
            ->setProject($project)
            ->setActivity($importRunContext->activity)
            ->setTicket($worklogSnapshot->issueKey)
            ->setDescription($worklogSnapshot->comment)
            ->setDay($times['day']->format('Y-m-d'))
            ->setStart($times['start']->format('H:i:s'))
            ->setEnd($times['end']->format('H:i:s'))
            ->setClass(EntryClass::PLAIN)
            ->setWorklogId((int) $jiraWorkLog->id);

        $customer = $project->getCustomer();
        if ($customer instanceof Customer) {
            $entry->setCustomer($customer);
        }

        $entry->setDuration($worklogSnapshot->durationMinutes);
        $entry->setSyncedToTicketsystem(true);

        $this->entityManager->persist($entry);

        $syncState = new WorklogSyncState()
            ->setEntry($entry)
            ->setTicketSystem($importRunContext->ticketSystem)
            ->setStatus(WorklogSyncStatus::IN_SYNC)
            ->setBasePayload($worklogSnapshot->toArray())
            ->setBaseUpdatedAt($jiraWorkLog->updated ?? '')
            ->setLastSyncedAt($this->now())
            ->setLastSyncRun($importRunContext->syncRun);
        $this->entityManager->persist($syncState);

        $importRunContext->syncRun->incrementCounter('created');

        $dayString = $times['day']->format('Y-m-d');
        $importRunContext->affectedDays[spl_object_id($user) . '|' . $dayString] = ['user' => $user, 'day' => $dayString];

        ++$importRunContext->createdSinceFlush;
        if ($importRunContext->createdSinceFlush >= 100) {
            $this->entityManager->flush();
            $importRunContext->createdSinceFlush = 0;
        }
    }
}

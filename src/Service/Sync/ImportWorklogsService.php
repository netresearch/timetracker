<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\SyncRun;
use App\Entity\SyncRunItem;
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
use function sprintf;
use function substr;

/**
 * ADR-023 Phase 2: imports Jira worklogs as pre-synced TT entries. Never dispatches
 * EntryEvent — imported entries must not echo back to Jira as new worklogs.
 */
class ImportWorklogsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EntryRepository $entryRepository,
        private readonly JiraOAuthApiFactory $jiraOAuthApiFactory,
        private readonly RemoteWorklogNormalizer $remoteWorklogNormalizer,
        private readonly TicketProjectResolver $ticketProjectResolver,
        private readonly JiraAuthorMapper $jiraAuthorMapper,
        private readonly DayClassService $dayClassService,
        private readonly ClockInterface $clock,
    ) {
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
            ->setStartedAt(DateTimeImmutable::createFromInterface($this->clock->now()));

        $this->entityManager->persist($syncRun);

        try {
            $this->run($syncRun, $triggeredBy, $ticketSystem, $from, $to, $defaultActivityId, $targetUsernames, $dryRun);
            $syncRun->setStatus(SyncRunStatus::COMPLETED);
        } catch (Throwable $throwable) {
            $syncRun->setStatus(SyncRunStatus::FAILED);
            $this->addItem($syncRun, SyncItemKind::ERROR, reason: substr($throwable->getMessage(), 0, 255));
        }

        $syncRun->setFinishedAt(DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();

        return $syncRun;
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

        $rangeFrom = $from->setTime(0, 0)->getTimestamp();
        $rangeTo = $to->setTime(23, 59, 59)->getTimestamp();

        /** @var array<string, ?User> $authorCache */
        $authorCache = [];
        /** @var array<string, true> $shadowAnnounced */
        $shadowAnnounced = [];
        /** @var array<string, array{userId: int, day: string}> $affectedDays */
        $affectedDays = [];
        $createdSinceFlush = 0;

        foreach ($searchResult->keys as $issueKey) {
            try {
                $issueWorklogs = $api->getIssueWorklogs($issueKey);
            } catch (Throwable $throwable) {
                $syncRun->incrementCounter('errors');
                $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, reason: substr('worklog fetch failed: ' . $throwable->getMessage(), 0, 255));
                continue;
            }

            $unresolvedAnnounced = false;

            foreach ($issueWorklogs as $jiraWorkLog) {
                if (null === $jiraWorkLog->id) {
                    continue;
                }

                try {
                    $snapshot = $this->remoteWorklogNormalizer->normalize($jiraWorkLog, $issueKey);
                } catch (InvalidArgumentException $exception) {
                    $syncRun->incrementCounter('errors');
                    $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $jiraWorkLog->id, reason: substr($exception->getMessage(), 0, 255));
                    continue;
                }
                if ($snapshot->startedTimestamp < $rangeFrom) {
                    continue;
                }
                if ($snapshot->startedTimestamp > $rangeTo) {
                    continue;
                }

                if ($this->entryRepository->findOneByWorklogIdAndTicketSystem($jiraWorkLog->id, $ticketSystem) instanceof Entry) {
                    $syncRun->incrementCounter('already_linked');
                    continue;
                }

                $remoteKey = $this->jiraAuthorMapper->remoteKey($jiraWorkLog);
                if (null === $remoteKey) {
                    $syncRun->incrementCounter('errors');
                    $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $jiraWorkLog->id, reason: 'worklog has no author identity');
                    continue;
                }

                if (!array_key_exists($remoteKey, $authorCache)) {
                    $authorCache[$remoteKey] = $this->jiraAuthorMapper->find($jiraWorkLog, $ticketSystem);
                }

                $user = $authorCache[$remoteKey];

                if ([] !== $targetUsernames && (!$user instanceof User || !in_array($user->getUsername(), $targetUsernames, true))) {
                    $syncRun->incrementCounter('skipped_author');
                    continue;
                }

                if (!$user instanceof User) {
                    if (!isset($shadowAnnounced[$remoteKey])) {
                        $shadowAnnounced[$remoteKey] = true;
                        $this->addItem(
                            $syncRun,
                            SyncItemKind::SHADOW_USER_CREATED,
                            issueKey: $issueKey,
                            author: $remoteKey,
                            reason: ($dryRun ? 'dry-run: would create shadow user for ' : 'created shadow user for ') . $remoteKey,
                        );
                        $syncRun->incrementCounter('shadow_users_created');
                    }

                    if ($dryRun) {
                        $syncRun->incrementCounter('would_create');
                        continue;
                    }

                    $user = $this->jiraAuthorMapper->createShadow($jiraWorkLog, $ticketSystem);
                    $authorCache[$remoteKey] = $user;
                }

                $resolution = $this->ticketProjectResolver->resolve($issueKey, $ticketSystem);
                $project = $resolution->project;
                if (!$project instanceof Project) {
                    $syncRun->incrementCounter('unresolved_project');
                    if (!$unresolvedAnnounced) {
                        $unresolvedAnnounced = true;
                        $this->addItem($syncRun, SyncItemKind::UNRESOLVED_PROJECT, issueKey: $issueKey, reason: substr($resolution->reason, 0, 255));
                    }

                    continue;
                }

                $day = new DateTime()->setTimestamp($snapshot->startedTimestamp);
                $start = clone $day;
                $end = (clone $start)->modify(sprintf('+%d minutes', $snapshot->durationMinutes));
                if ($end->format('Y-m-d') !== $day->format('Y-m-d')) {
                    $syncRun->incrementCounter('errors');
                    $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $jiraWorkLog->id, reason: 'worklog crosses midnight; import manually');
                    continue;
                }

                $duplicate = $this->entryRepository->findUnlinkedDuplicate($user, $issueKey, $day, $snapshot->durationMinutes);
                if ($duplicate instanceof Entry) {
                    $syncRun->incrementCounter('probable_duplicate');
                    $this->addItem(
                        $syncRun,
                        SyncItemKind::PROBABLE_DUPLICATE,
                        issueKey: $issueKey,
                        remoteWorklogId: $jiraWorkLog->id,
                        entry: $duplicate,
                        author: $remoteKey,
                        reason: sprintf('unlinked entry %d matches user+ticket+day+duration', (int) $duplicate->getId()),
                        payload: ['remote' => $snapshot->toArray(), 'updated' => $jiraWorkLog->updated],
                    );
                    continue;
                }

                if ($dryRun) {
                    $syncRun->incrementCounter('would_create');
                    continue;
                }

                $this->createEntry($syncRun, $user, $project, $activity, $ticketSystem, $issueKey, $jiraWorkLog->id, $snapshot, $jiraWorkLog->updated, $day, $start, $end);
                $dayKey = $user->getId() . '|' . $day->format('Y-m-d');
                $affectedDays[$dayKey] = ['userId' => (int) $user->getId(), 'day' => $day->format('Y-m-d')];

                ++$createdSinceFlush;
                if ($createdSinceFlush >= 100) {
                    $this->entityManager->flush();
                    $createdSinceFlush = 0;
                }
            }
        }

        $this->entityManager->flush();

        foreach ($affectedDays as $affected) {
            $this->dayClassService->recalculate($affected['userId'], $affected['day']);
        }
    }

    private function createEntry(
        SyncRun $syncRun,
        User $user,
        Project $project,
        Activity $activity,
        TicketSystem $ticketSystem,
        string $issueKey,
        int $worklogId,
        WorklogSnapshot $snapshot,
        ?string $remoteUpdated,
        DateTime $day,
        DateTime $start,
        DateTime $end,
    ): void {
        $entry = new Entry();
        $entry->setUser($user)
            ->setProject($project)
            ->setActivity($activity)
            ->setTicket($issueKey)
            ->setDescription($snapshot->comment)
            ->setDay($day->format('Y-m-d'))
            ->setStart($start->format('H:i:s'))
            ->setEnd($end->format('H:i:s'))
            ->setClass(EntryClass::PLAIN)
            ->setWorklogId($worklogId);

        $customer = $project->getCustomer();
        if ($customer instanceof Customer) {
            $entry->setCustomer($customer);
        }

        $entry->setDuration($snapshot->durationMinutes);
        $entry->setSyncedToTicketsystem(true);

        $this->entityManager->persist($entry);

        $syncState = new WorklogSyncState()
            ->setEntry($entry)
            ->setTicketSystem($ticketSystem)
            ->setStatus(WorklogSyncStatus::IN_SYNC)
            ->setBasePayload($snapshot->toArray())
            ->setBaseUpdatedAt($remoteUpdated ?? '')
            ->setLastSyncedAt(DateTimeImmutable::createFromInterface($this->clock->now()))
            ->setLastSyncRun($syncRun);
        $this->entityManager->persist($syncState);

        $syncRun->incrementCounter('created');
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function addItem(
        SyncRun $syncRun,
        SyncItemKind $kind,
        ?string $issueKey = null,
        ?int $remoteWorklogId = null,
        ?Entry $entry = null,
        ?string $author = null,
        string $reason = '',
        ?array $payload = null,
    ): void {
        $syncRun->addItem(
            new SyncRunItem()
                ->setKind($kind)
                ->setIssueKey($issueKey)
                ->setRemoteWorklogId($remoteWorklogId)
                ->setEntry($entry)
                ->setAuthor($author)
                ->setReason($reason)
                ->setPayload($payload)
                ->setCreatedAt(DateTimeImmutable::createFromInterface($this->clock->now())),
        );
    }
}

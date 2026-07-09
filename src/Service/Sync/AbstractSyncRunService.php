<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Entry;
use App\Entity\SyncRun;
use App\Entity\SyncRunItem;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

use function substr;

/**
 * Shared scaffold for worklog sync runs (ADR-023 §4): run lifecycle and item recording.
 */
abstract class AbstractSyncRunService
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly ClockInterface $clock,
    ) {
    }

    /**
     * Persists the run, executes $body, finalizes status and timestamps, flushes.
     *
     * @param callable(): void $body
     */
    protected function executeRun(SyncRun $syncRun, callable $body): SyncRun
    {
        $this->entityManager->persist($syncRun);

        $failure = null;

        try {
            $body();
            $syncRun->setStatus(SyncRunStatus::COMPLETED);
        } catch (Throwable $throwable) {
            $failure = $throwable;
            $syncRun->setStatus(SyncRunStatus::FAILED);
            // Only touch the (unit-of-work) collection while the manager is still usable —
            // a persist that violated a DB constraint closes the EM mid-run.
            if ($this->entityManager->isOpen()) {
                $this->addItem($syncRun, SyncItemKind::ERROR, reason: substr($throwable->getMessage(), 0, 255));
            }
        }

        $syncRun->setFinishedAt($this->now());

        if ($failure instanceof Throwable && !$this->entityManager->isOpen()) {
            // A persisted row was rejected by the database and closed the manager; the run
            // record cannot be saved through it. Surface the real cause rather than the
            // opaque EntityManagerClosed a retry flush would throw.
            throw new RuntimeException('Sync run aborted: the entity manager closed mid-run (a persisted row was rejected by the database). Original error: ' . $failure->getMessage(), 0, $failure);
        }

        $this->entityManager->flush();

        return $syncRun;
    }

    protected function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    protected function addItem(
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
                ->setCreatedAt($this->now()),
        );
    }
}

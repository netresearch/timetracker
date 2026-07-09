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

        try {
            $body();
            $syncRun->setStatus(SyncRunStatus::COMPLETED);
        } catch (Throwable $throwable) {
            $syncRun->setStatus(SyncRunStatus::FAILED);
            $this->addItem($syncRun, SyncItemKind::ERROR, reason: substr($throwable->getMessage(), 0, 255));
        }

        $syncRun->setFinishedAt($this->now());
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

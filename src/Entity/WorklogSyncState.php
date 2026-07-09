<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WorklogSyncStatus;
use App\Model\Base;
use App\Repository\WorklogSyncStateRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lease base state for one entry's Jira worklog (ADR-023 §2).
 */
#[ORM\Entity(repositoryClass: WorklogSyncStateRepository::class)]
#[ORM\Table(name: 'worklog_sync_state')]
class WorklogSyncState extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\OneToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(name: 'entry_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    protected ?Entry $entry = null;

    #[ORM\ManyToOne(targetEntity: TicketSystem::class)]
    #[ORM\JoinColumn(name: 'ticket_system_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?TicketSystem $ticketSystem = null;

    #[ORM\Column(type: 'string', length: 16, enumType: WorklogSyncStatus::class)]
    protected WorklogSyncStatus $status = WorklogSyncStatus::IN_SYNC;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'base_payload', type: 'json')]
    protected array $basePayload = [];

    /**
     * Raw Jira `updated` string at last sync — compared verbatim for the lease (CAS).
     */
    #[ORM\Column(name: 'base_updated_at', type: 'string', length: 40)]
    protected string $baseUpdatedAt = '';

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'conflict_remote_payload', type: 'json', nullable: true)]
    protected ?array $conflictRemotePayload = null;

    #[ORM\Column(name: 'last_synced_at', type: 'datetime_immutable')]
    protected ?DateTimeImmutable $lastSyncedAt = null;

    #[ORM\ManyToOne(targetEntity: SyncRun::class)]
    #[ORM\JoinColumn(name: 'last_sync_run_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?SyncRun $lastSyncRun = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntry(): ?Entry
    {
        return $this->entry;
    }

    public function setEntry(Entry $entry): static
    {
        $this->entry = $entry;

        return $this;
    }

    public function getTicketSystem(): ?TicketSystem
    {
        return $this->ticketSystem;
    }

    public function setTicketSystem(TicketSystem $ticketSystem): static
    {
        $this->ticketSystem = $ticketSystem;

        return $this;
    }

    public function getStatus(): WorklogSyncStatus
    {
        return $this->status;
    }

    public function setStatus(WorklogSyncStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getBasePayload(): array
    {
        return $this->basePayload;
    }

    /** @param array<string, mixed> $basePayload */
    public function setBasePayload(array $basePayload): static
    {
        $this->basePayload = $basePayload;

        return $this;
    }

    public function getBaseUpdatedAt(): string
    {
        return $this->baseUpdatedAt;
    }

    public function setBaseUpdatedAt(string $baseUpdatedAt): static
    {
        $this->baseUpdatedAt = $baseUpdatedAt;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getConflictRemotePayload(): ?array
    {
        return $this->conflictRemotePayload;
    }

    /** @param array<string, mixed>|null $conflictRemotePayload */
    public function setConflictRemotePayload(?array $conflictRemotePayload): static
    {
        $this->conflictRemotePayload = $conflictRemotePayload;

        return $this;
    }

    public function getLastSyncedAt(): ?DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getLastSyncRun(): ?SyncRun
    {
        return $this->lastSyncRun;
    }

    public function setLastSyncRun(?SyncRun $syncRun): static
    {
        $this->lastSyncRun = $syncRun;

        return $this;
    }
}

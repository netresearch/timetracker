<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Model\Base;
use App\Repository\PersonioAttendanceExportRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per (user, day) record of the Personio attendance periods TT created (ADR-024 §3):
 * the TT-owned period ids and the last-sent projection, so the export updates only
 * its own records idempotently.
 */
#[ORM\Entity(repositoryClass: PersonioAttendanceExportRepository::class)]
#[ORM\Table(name: 'personio_attendance_export')]
#[ORM\UniqueConstraint(name: 'uniq_personio_export_user_day', columns: ['user_id', 'day'])]
#[ORM\Index(name: 'idx_personio_export_user', columns: ['user_id'])]
class PersonioAttendanceExport extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?User $user = null;

    #[ORM\Column(type: 'date')]
    protected ?DateTimeInterface $day = null;

    /** @var list<string> */
    #[ORM\Column(name: 'period_ids', type: 'json')]
    protected array $periodIds = [];

    /** @var list<array{start: int, end: int}> */
    #[ORM\Column(name: 'base_payload', type: 'json')]
    protected array $basePayload = [];

    #[ORM\Column(name: 'last_exported_at', type: 'datetime_immutable')]
    protected ?DateTimeImmutable $lastExportedAt = null;

    #[ORM\ManyToOne(targetEntity: SyncRun::class)]
    #[ORM\JoinColumn(name: 'last_sync_run_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?SyncRun $lastSyncRun = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getDay(): ?DateTimeInterface
    {
        return $this->day;
    }

    public function setDay(DateTimeInterface $day): static
    {
        $this->day = $day;

        return $this;
    }

    /** @return list<string> */
    public function getPeriodIds(): array
    {
        return $this->periodIds;
    }

    /** @param list<string> $periodIds */
    public function setPeriodIds(array $periodIds): static
    {
        $this->periodIds = $periodIds;

        return $this;
    }

    /** @return list<array{start: int, end: int}> */
    public function getBasePayload(): array
    {
        return $this->basePayload;
    }

    /** @param list<array{start: int, end: int}> $basePayload */
    public function setBasePayload(array $basePayload): static
    {
        $this->basePayload = $basePayload;

        return $this;
    }

    public function getLastExportedAt(): ?DateTimeImmutable
    {
        return $this->lastExportedAt;
    }

    public function setLastExportedAt(DateTimeImmutable $lastExportedAt): static
    {
        $this->lastExportedAt = $lastExportedAt;

        return $this;
    }

    public function getLastSyncRun(): ?SyncRun
    {
        return $this->lastSyncRun;
    }

    public function setLastSyncRun(?SyncRun $lastSyncRun): static
    {
        $this->lastSyncRun = $lastSyncRun;

        return $this;
    }
}

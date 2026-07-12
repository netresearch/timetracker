<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Model\Base;
use App\Repository\PersonioAbsenceImportRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per Personio absence, the TT entries the import created for it (ADR-024 §4):
 * the created entry ids plus a signature of the source absence, so re-runs are
 * idempotent (unchanged -> skip) and a cancelled/changed absence can delete or
 * rebuild exactly its own entries.
 */
#[ORM\Entity(repositoryClass: PersonioAbsenceImportRepository::class)]
#[ORM\Table(name: 'personio_absence_import')]
#[ORM\UniqueConstraint(name: 'uniq_personio_absence_id', columns: ['absence_id'])]
#[ORM\Index(name: 'idx_personio_absence_user', columns: ['user_id'])]
class PersonioAbsenceImport extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?User $user = null;

    #[ORM\Column(name: 'absence_id', type: 'string', length: 191)]
    protected ?string $absenceId = null;

    /** @var list<int> */
    #[ORM\Column(name: 'entry_ids', type: 'json')]
    protected array $entryIds = [];

    /**
     * The source-absence fields that, when they change, mean the import must
     * rebuild its entries: start/end date-time, the half-day boundary markers,
     * and the absence type id.
     *
     * @var array<string, string|null>
     */
    #[ORM\Column(name: 'signature', type: 'json')]
    protected array $signature = [];

    #[ORM\Column(name: 'last_imported_at', type: 'datetime_immutable')]
    protected ?DateTimeImmutable $lastImportedAt = null;

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

    public function getAbsenceId(): ?string
    {
        return $this->absenceId;
    }

    public function setAbsenceId(string $absenceId): static
    {
        $this->absenceId = $absenceId;

        return $this;
    }

    /** @return list<int> */
    public function getEntryIds(): array
    {
        return $this->entryIds;
    }

    /** @param list<int> $entryIds */
    public function setEntryIds(array $entryIds): static
    {
        $this->entryIds = $entryIds;

        return $this;
    }

    /** @return array<string, string|null> */
    public function getSignature(): array
    {
        return $this->signature;
    }

    /** @param array<string, string|null> $signature */
    public function setSignature(array $signature): static
    {
        $this->signature = $signature;

        return $this;
    }

    public function getLastImportedAt(): ?DateTimeImmutable
    {
        return $this->lastImportedAt;
    }

    public function setLastImportedAt(DateTimeImmutable $lastImportedAt): static
    {
        $this->lastImportedAt = $lastImportedAt;

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

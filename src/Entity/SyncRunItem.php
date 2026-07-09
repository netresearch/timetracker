<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SyncItemKind;
use App\Model\Base;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sync_run_items')]
#[ORM\Index(name: 'idx_sync_run_items_run_kind', columns: ['sync_run_id', 'kind'])]
class SyncRunItem extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SyncRun::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'sync_run_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?SyncRun $syncRun = null;

    #[ORM\Column(type: 'string', length: 32, enumType: SyncItemKind::class)]
    protected SyncItemKind $kind = SyncItemKind::ERROR;

    #[ORM\Column(name: 'issue_key', type: 'string', length: 50, nullable: true)]
    protected ?string $issueKey = null;

    #[ORM\Column(name: 'remote_worklog_id', type: 'bigint', nullable: true)]
    protected ?int $remoteWorklogId = null;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(name: 'entry_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Entry $entry = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $author = null;

    #[ORM\Column(type: 'string', length: 255)]
    protected string $reason = '';

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $payload = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    protected ?DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSyncRun(): ?SyncRun
    {
        return $this->syncRun;
    }

    public function setSyncRun(SyncRun $syncRun): static
    {
        $this->syncRun = $syncRun;

        return $this;
    }

    public function getKind(): SyncItemKind
    {
        return $this->kind;
    }

    public function setKind(SyncItemKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getIssueKey(): ?string
    {
        return $this->issueKey;
    }

    public function setIssueKey(?string $issueKey): static
    {
        $this->issueKey = $issueKey;

        return $this;
    }

    public function getRemoteWorklogId(): ?int
    {
        return $this->remoteWorklogId;
    }

    public function setRemoteWorklogId(?int $remoteWorklogId): static
    {
        $this->remoteWorklogId = $remoteWorklogId;

        return $this;
    }

    public function getEntry(): ?Entry
    {
        return $this->entry;
    }

    public function setEntry(?Entry $entry): static
    {
        $this->entry = $entry;

        return $this;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /** @param array<string, mixed>|null $payload */
    public function setPayload(?array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}

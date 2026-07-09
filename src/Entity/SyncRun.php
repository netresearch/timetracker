<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Model\Base;
use App\Repository\SyncRunRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SyncRunRepository::class)]
#[ORM\Table(name: 'sync_runs')]
class SyncRun extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 16, enumType: SyncRunType::class)]
    protected SyncRunType $type = SyncRunType::VERIFY;

    #[ORM\Column(type: 'string', length: 16, enumType: SyncRunStatus::class)]
    protected SyncRunStatus $status = SyncRunStatus::RUNNING;

    #[ORM\ManyToOne(targetEntity: TicketSystem::class)]
    #[ORM\JoinColumn(name: 'ticket_system_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?TicketSystem $ticketSystem = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'triggered_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?User $triggeredBy = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    protected array $scope = [];

    /** @var array<string, int> */
    #[ORM\Column(type: 'json')]
    protected array $counters = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $continuation = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    protected ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, SyncRunItem> */
    #[ORM\OneToMany(targetEntity: SyncRunItem::class, mappedBy: 'syncRun', cascade: ['persist'])]
    protected Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): SyncRunType
    {
        return $this->type;
    }

    public function setType(SyncRunType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): SyncRunStatus
    {
        return $this->status;
    }

    public function setStatus(SyncRunStatus $status): static
    {
        $this->status = $status;

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

    public function getTriggeredBy(): ?User
    {
        return $this->triggeredBy;
    }

    public function setTriggeredBy(User $user): static
    {
        $this->triggeredBy = $user;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getScope(): array
    {
        return $this->scope;
    }

    /** @param array<string, mixed> $scope */
    public function setScope(array $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    /** @return array<string, int> */
    public function getCounters(): array
    {
        return $this->counters;
    }

    /** @param array<string, int> $counters */
    public function setCounters(array $counters): static
    {
        $this->counters = $counters;

        return $this;
    }

    public function incrementCounter(string $key): void
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    /** @return array<string, mixed>|null */
    public function getContinuation(): ?array
    {
        return $this->continuation;
    }

    /** @param array<string, mixed>|null $continuation */
    public function setContinuation(?array $continuation): static
    {
        $this->continuation = $continuation;

        return $this;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /** @return Collection<int, SyncRunItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(SyncRunItem $item): static
    {
        $this->items->add($item);
        $item->setSyncRun($this);

        return $this;
    }
}

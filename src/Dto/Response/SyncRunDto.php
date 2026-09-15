<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\SyncRun;
use JsonSerializable;

use function array_map;
use function array_values;

use const DATE_ATOM;

/**
 * A sync/import/verify run with its counters and (optionally) its per-worklog
 * findings — the shape the v2 run endpoints and MCP sync tools return
 * (ADR-023 §6). Item-less serialization serves run listings.
 */
final readonly class SyncRunDto implements JsonSerializable
{
    /**
     * @param array<string, mixed>      $scope
     * @param array<string, int>        $counters
     * @param list<SyncRunItemDto>|null $items    null omits the key entirely
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $status,
        public int $ticketSystemId,
        public ?string $triggeredBy,
        public array $scope,
        public array $counters,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?array $items,
    ) {
    }

    public static function fromEntity(SyncRun $syncRun, bool $withItems = true): self
    {
        $items = null;
        if ($withItems) {
            $items = array_values(array_map(
                SyncRunItemDto::fromEntity(...),
                $syncRun->getItems()->toArray(),
            ));
        }

        return new self(
            id: (int) $syncRun->getId(),
            type: $syncRun->getType()->value,
            status: $syncRun->getStatus()->value,
            ticketSystemId: (int) $syncRun->getTicketSystem()?->getId(),
            triggeredBy: $syncRun->getTriggeredBy()?->getUsername(),
            scope: $syncRun->getScope(),
            counters: $syncRun->getCounters(),
            startedAt: $syncRun->getStartedAt()?->format(DATE_ATOM),
            finishedAt: $syncRun->getFinishedAt()?->format(DATE_ATOM),
            items: $items,
        );
    }

    /**
     * @return array{id: int, type: string, status: string, ticket_system_id: int, triggered_by: string|null, scope: array<string, mixed>, counters: array<string, int>, started_at: string|null, finished_at: string|null, items?: list<array{kind: string, issue_key: string|null, remote_worklog_id: int|null, entry_id: int|null, author: string|null, reason: string, payload: array<string, mixed>|null, created_at: string|null}>}
     */
    public function jsonSerialize(): array
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'ticket_system_id' => $this->ticketSystemId,
            'triggered_by' => $this->triggeredBy,
            'scope' => $this->scope,
            'counters' => $this->counters,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];

        if (null !== $this->items) {
            $data['items'] = array_map(
                static fn (SyncRunItemDto $item): array => $item->jsonSerialize(),
                $this->items,
            );
        }

        return $data;
    }
}

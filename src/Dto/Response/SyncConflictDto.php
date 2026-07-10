<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\WorklogSyncState;
use JsonSerializable;

use const DATE_ATOM;

/**
 * A parked worklog sync state (CONFLICT or ORPHANED) with the local entry,
 * the lease base, and the stored remote snapshot — everything a caller needs
 * to pick a winner (ADR-023 §6). `conflict_remote` is display material; the
 * resolution service re-fetches the live remote before applying.
 */
final readonly class SyncConflictDto implements JsonSerializable
{
    /**
     * @param array{id: int, user: string, ticket: string, day: string, start: string, end: string, duration: int, description: string} $entry
     * @param array<string, mixed>                                                                                                      $basePayload
     * @param array<string, mixed>|null                                                                                                 $conflictRemote
     */
    public function __construct(
        public int $id,
        public string $status,
        public array $entry,
        public array $basePayload,
        public string $baseUpdatedAt,
        public ?array $conflictRemote,
        public ?string $lastSyncedAt,
    ) {
    }

    public static function fromEntity(WorklogSyncState $state): self
    {
        $entry = $state->getEntry();

        return new self(
            id: (int) $state->getId(),
            status: $state->getStatus()->value,
            entry: [
                'id' => (int) $entry?->getId(),
                'user' => (string) $entry?->getUser()?->getUsername(),
                'ticket' => (string) $entry?->getTicket(),
                'day' => (string) $entry?->getDay()->format('Y-m-d'),
                'start' => (string) $entry?->getStart()->format('H:i:s'),
                'end' => (string) $entry?->getEnd()->format('H:i:s'),
                'duration' => (int) $entry?->getDuration(),
                'description' => (string) $entry?->getDescription(),
            ],
            basePayload: $state->getBasePayload(),
            baseUpdatedAt: $state->getBaseUpdatedAt(),
            conflictRemote: $state->getConflictRemotePayload(),
            lastSyncedAt: $state->getLastSyncedAt()?->format(DATE_ATOM),
        );
    }

    /**
     * @return array{id: int, status: string, entry: array{id: int, user: string, ticket: string, day: string, start: string, end: string, duration: int, description: string}, base_payload: array<string, mixed>, base_updated_at: string, conflict_remote: array<string, mixed>|null, last_synced_at: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'entry' => $this->entry,
            'base_payload' => $this->basePayload,
            'base_updated_at' => $this->baseUpdatedAt,
            'conflict_remote' => $this->conflictRemote,
            'last_synced_at' => $this->lastSyncedAt,
        ];
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\UserTicketsystem;
use JsonSerializable;

/**
 * The caller's per-ticket-system worklog sync opt-in flags (ADR-023
 * amendment) — the shape the worklog-sync preferences endpoints return.
 */
final readonly class WorklogSyncPreferenceDto implements JsonSerializable
{
    public function __construct(
        public int $ticketSystemId,
        public string $ticketSystemName,
        public bool $syncEnabled,
        public bool $syncAll,
    ) {
    }

    public static function fromEntity(UserTicketsystem $userTicketsystem): self
    {
        $ticketSystem = $userTicketsystem->getTicketSystem();

        return new self(
            ticketSystemId: (int) $ticketSystem?->getId(),
            ticketSystemName: $ticketSystem?->getName() ?? '',
            syncEnabled: $userTicketsystem->getSyncEnabled(),
            syncAll: $userTicketsystem->getSyncAll(),
        );
    }

    /**
     * @return array{ticket_system_id: int, ticket_system_name: string, sync_enabled: bool, sync_all: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'ticket_system_id' => $this->ticketSystemId,
            'ticket_system_name' => $this->ticketSystemName,
            'sync_enabled' => $this->syncEnabled,
            'sync_all' => $this->syncAll,
        ];
    }
}

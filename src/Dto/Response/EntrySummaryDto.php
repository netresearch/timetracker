<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use JsonSerializable;

/**
 * The per-scope booking aggregation the tracking UI shows in an entry's
 * "Info" (I) popup — customer / project / activity / ticket — plus the
 * project-estimate verdict and agent-facing warnings (ADR-022). Serialized
 * identically by GET /api/v2/entries/{id}/summary and the get_ticket_info
 * MCP tool.
 */
final readonly class EntrySummaryDto implements JsonSerializable
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public ScopeSummaryDto $customer,
        public ScopeSummaryDto $project,
        public ScopeSummaryDto $activity,
        public ScopeSummaryDto $ticket,
        public EstimateDto $estimate,
        public array $warnings,
    ) {
    }

    /**
     * @return array{
     *     customer: array{scope: string, name: string, entries: int, total: int, own: int, estimation: int},
     *     project: array{scope: string, name: string, entries: int, total: int, own: int, estimation: int},
     *     activity: array{scope: string, name: string, entries: int, total: int, own: int, estimation: int},
     *     ticket: array{scope: string, name: string, entries: int, total: int, own: int, estimation: int},
     *     estimate: array{estimation: int, booked_total: int, percent: int|null, status: string},
     *     warnings: list<string>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'customer' => $this->customer->jsonSerialize(),
            'project' => $this->project->jsonSerialize(),
            'activity' => $this->activity->jsonSerialize(),
            'ticket' => $this->ticket->jsonSerialize(),
            'estimate' => $this->estimate->jsonSerialize(),
            'warnings' => $this->warnings,
        ];
    }
}

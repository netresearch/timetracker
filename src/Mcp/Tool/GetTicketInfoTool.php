<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Response\EntrySummaryDto;
use App\Mcp\ScopeGuard;
use App\Service\EntrySummaryService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

use function sprintf;

/**
 * MCP tool: the "Info" (I) aggregation for a booked entry (ADR-021 Phase 5).
 */
final readonly class GetTicketInfoTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private EntrySummaryService $entrySummaryService,
    ) {
    }

    /**
     * For a given entry, the per-scope booking totals shown in the tracking UI's
     * "Info" popup: Customer / Project / Activity / Ticket, each with the user's
     * own booked minutes (`own`), everyone's total (`total`) and the estimate
     * (`estimation`, project only), plus an `estimate` summary and `warnings`.
     * Get entry ids from `list_recent_entries` or `log_time`.
     *
     * @throws ToolCallException when the entry does not exist
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_ticket_info', description: 'Per-scope booking totals (customer/project/activity/ticket) and estimate status for an entry.')]
    public function getTicketInfo(
        #[Schema(description: 'The id of a time entry to report on.', minimum: 1)]
        int $entryId,
    ): array {
        $user = $this->scopeGuard->requireScope('reporting:read');

        $info = $this->entrySummaryService->forEntry($entryId, (int) $user->getId());
        if (!$info instanceof EntrySummaryDto) {
            throw new ToolCallException(sprintf('No entry with id %d.', $entryId));
        }

        return $info->jsonSerialize();
    }
}

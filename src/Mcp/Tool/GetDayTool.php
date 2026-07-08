<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\ScopeGuard;
use App\Service\DaySummaryService;
use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * MCP tool: the caller's own bookings for one day (ADR-022 Phase 2).
 */
final readonly class GetDayTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private DaySummaryService $daySummaryService,
    ) {
    }

    /**
     * The authenticated user's own time entries for one day (default: today),
     * in start order, plus the booked total — the same day list the tracking
     * UI shows. Use it to check what is already booked before logging time.
     *
     * @throws ToolCallException on an invalid date
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_day', description: "The authenticated user's own time entries and booked total for one day (default: today).")]
    public function getDay(
        #[Schema(description: 'The day as YYYY-MM-DD. Defaults to today.')]
        ?string $date = null,
    ): array {
        $user = $this->scopeGuard->requireScope('entries:read');

        try {
            return $this->daySummaryService->forUser($user, $date)->jsonSerialize();
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ToolCallException($invalidArgumentException->getMessage(), $invalidArgumentException->getCode(), previous: $invalidArgumentException);
        }
    }
}

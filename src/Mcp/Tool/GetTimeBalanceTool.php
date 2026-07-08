<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\ScopeGuard;
use App\Service\TimeBalanceService;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP tool: the authenticated user's worked-vs-target balance (ADR-021 Phase 5).
 */
final readonly class GetTimeBalanceTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private TimeBalanceService $timeBalanceService,
    ) {
    }

    /**
     * The user's time balance for today, this week and this month: IST (worked
     * minutes), SOLL for the whole period and through today, the difference, a
     * per-period status (`ok`/`behind`/`over`), and a `warnings` list to act on.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_time_balance', description: "The authenticated user's worked-vs-target time balance for today, this week and this month.")]
    public function getTimeBalance(): array
    {
        $user = $this->scopeGuard->requireScope('reporting:read');

        return $this->timeBalanceService->forUser($user)->jsonSerialize();
    }
}

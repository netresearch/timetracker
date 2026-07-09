<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Controller\Tracking\BulkEntryAction;
use App\Mcp\AdminEntityResolver;
use App\Mcp\ScopeGuard;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function trim;

/**
 * MCP tool: bulk-fill a date range from a preset (ADR-022 Phase 4) — the
 * "Massen-Eintragung" the UI offers. Delegates to the same BulkEntryAction, so
 * contract-hours, weekend and holiday handling are identical.
 */
final readonly class BulkLogTimeTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private BulkEntryAction $bulkEntryAction,
        private AdminEntityResolver $resolver,
    ) {
    }

    /**
     * Create one entry per working day in a date range from a saved preset
     * (customer/project/activity/description). Give the preset by name or id
     * (see `list_presets`). Weekends and public holidays are skipped by
     * default; with `useContract` the daily hours come from the user's contract.
     *
     * @throws ToolCallException on an unknown preset or a validation failure
     *
     * @return array<string, mixed> a summary message of what was booked
     */
    #[McpTool(name: 'bulk_log_time', description: 'Bulk-fill a date range from a preset (the UI\'s "Massen-Eintragung").')]
    public function bulkLogTime(
        #[Schema(description: 'Preset name or numeric id. See list_presets.')]
        string $preset,
        #[Schema(description: 'First day, YYYY-MM-DD.')]
        string $startDate,
        #[Schema(description: 'Last day, YYYY-MM-DD (inclusive).')]
        string $endDate,
        #[Schema(description: 'Use the daily hours from the user\'s contract instead of a fixed start/end.')]
        bool $useContract = true,
        #[Schema(description: 'Skip Saturdays and Sundays.')]
        bool $skipWeekend = true,
        #[Schema(description: 'Skip public holidays.')]
        bool $skipHolidays = true,
        #[Schema(description: 'Start time HH:MM when not using contract hours.')]
        string $startTime = '',
        #[Schema(description: 'End time HH:MM when not using contract hours.')]
        string $endTime = '',
    ): array {
        $user = $this->scopeGuard->requireScope('entries:write');
        $presetEntity = $this->resolver->preset($preset);

        $request = new Request(request: [
            'preset' => (string) $presetEntity->getId(),
            'startdate' => trim($startDate),
            'enddate' => trim($endDate),
            'starttime' => trim($startTime),
            'endtime' => trim($endTime),
            'usecontract' => $useContract ? '1' : '0',
            'skipweekend' => $skipWeekend ? '1' : '0',
            'skipholidays' => $skipHolidays ? '1' : '0',
        ]);

        $response = ($this->bulkEntryAction)($request, $user);
        $message = trim((string) $response->getContent());

        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            throw new ToolCallException('' !== $message ? $message : 'Bulk entry failed.');
        }

        return ['success' => true, 'message' => $message];
    }
}

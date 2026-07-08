<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use JsonSerializable;

/**
 * The authenticated user's today/week/month worked-vs-target balance, with
 * agent-facing warnings when a target is missed or exceeded (ADR-022).
 * Serialized identically by GET /api/v2/time-balance and the get_time_balance
 * MCP tool — single contract, no drift.
 */
final readonly class TimeBalanceDto implements JsonSerializable
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public PeriodBalanceDto $today,
        public PeriodBalanceDto $week,
        public PeriodBalanceDto $month,
        public array $warnings,
    ) {
    }

    /**
     * @return array{
     *     today: array{ist: int, soll_total: int, soll_so_far: int, diff: int, status: string},
     *     week: array{ist: int, soll_total: int, soll_so_far: int, diff: int, status: string},
     *     month: array{ist: int, soll_total: int, soll_so_far: int, diff: int, status: string},
     *     warnings: list<string>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'today' => $this->today->jsonSerialize(),
            'week' => $this->week->jsonSerialize(),
            'month' => $this->month->jsonSerialize(),
            'warnings' => $this->warnings,
        ];
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use App\Enum\BalanceStatus;
use JsonSerializable;

/**
 * One balance period (today / week / month): worked minutes (IST) against the
 * expected minutes (SOLL) for the whole period and accrued through today.
 * Wire keys per ADR-022 §4 (the keys the MCP tools shipped with).
 */
final readonly class PeriodBalanceDto implements JsonSerializable
{
    public function __construct(
        public int $ist,
        public int $sollTotal,
        public int $sollSoFar,
        public int $diff,
        public BalanceStatus $status,
    ) {
    }

    /**
     * @return array{ist: int, soll_total: int, soll_so_far: int, diff: int, status: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'ist' => $this->ist,
            'soll_total' => $this->sollTotal,
            'soll_so_far' => $this->sollSoFar,
            'diff' => $this->diff,
            'status' => $this->status->value,
        ];
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use App\Enum\EstimateStatus;
use JsonSerializable;

/**
 * Project-estimate summary for an entry: how much of the project's estimate is
 * booked (all users), as absolute minutes and percent, with a verdict the
 * consumer can act on (ADR-022). `percent` is null when there is no estimate.
 */
final readonly class EstimateDto implements JsonSerializable
{
    public function __construct(
        public int $estimation,
        public int $bookedTotal,
        public ?int $percent,
        public EstimateStatus $status,
    ) {
    }

    /**
     * @return array{estimation: int, booked_total: int, percent: int|null, status: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'estimation' => $this->estimation,
            'booked_total' => $this->bookedTotal,
            'percent' => $this->percent,
            'status' => $this->status->value,
        ];
    }
}

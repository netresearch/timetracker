<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of PATCH /api/v2/entries/{id} (ADR-022 Phase 4): a partial
 * update — every property is nullable and null means "keep the current value".
 * The merge + time resolution happens in EntryUpdateService.
 */
final readonly class EntryPatchDto
{
    public function __construct(
        #[Assert\Positive]
        public ?int $project_id = null,
        #[Assert\Positive]
        public ?int $activity_id = null,
        public ?string $ticket = null,
        public ?string $description = null,
        public ?string $date = null,
        #[Assert\Positive]
        public ?int $durationMinutes = null,
        public ?string $start = null,
        public ?string $end = null,
    ) {
    }
}

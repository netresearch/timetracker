<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of POST /api/v2/bulk-entries (ADR-022 Phase 4): fill a date
 * range from a preset. Detailed date/time validation is done by BulkEntryDto
 * inside BulkEntryAction, which this endpoint delegates to.
 */
final readonly class BulkEntriesDto
{
    public function __construct(
        #[Assert\Positive(message: 'A preset id is required.')]
        public int $preset_id = 0,
        #[Assert\NotBlank]
        public string $start_date = '',
        #[Assert\NotBlank]
        public string $end_date = '',
        public bool $use_contract = true,
        public bool $skip_weekend = true,
        public bool $skip_holidays = true,
        public string $start_time = '',
        public string $end_time = '',
    ) {
    }
}

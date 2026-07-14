<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of PATCH /api/v2/settings. Every field is optional;
 * an absent (null) field leaves the stored value unchanged — that
 * partial semantics is what lets the settings page's sections save
 * independently (spec §6).
 */
final readonly class UpdateUserSettingsDto
{
    public function __construct(
        public ?string $locale = null,
        public ?bool $show_empty_line = null,
        public ?bool $suggest_time = null,
        public ?bool $show_future = null,
        #[Assert\Range(min: 0, max: 1440)]
        public ?int $min_entry_duration = null,
        public ?bool $personio_sync_enabled = null,
    ) {
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Delete payload for a holiday. The primary key is the day (holidays have no
 * numeric id), so the day identifies the row to remove.
 */
final readonly class HolidayDeleteDto
{
    public function __construct(
        // Assert\Date rejects impossible calendar dates (e.g. 2026-02-31) that
        // `new DateTime()` would silently roll over instead of throwing on.
        #[Assert\NotBlank(message: 'Please provide a valid date.')]
        #[Assert\Date(message: 'Please provide a valid date.')]
        public string $day = '',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            day: (string) ($request->request->get('day') ?? ''),
        );
    }
}

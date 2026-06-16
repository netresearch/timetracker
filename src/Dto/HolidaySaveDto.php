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
 * Create payload for a holiday. Holidays are immutable and keyed by day, so
 * there is no id and no update path — the DTO is constructed fresh each time
 * (no #[Map] auto-mapping: the Holiday entity is constructor-only).
 */
final readonly class HolidaySaveDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Please provide a valid date.')]
        public string $day = '',
        #[Assert\NotBlank(message: 'Please provide a holiday name.')]
        public string $name = '',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            day: (string) ($request->request->get('day') ?? ''),
            name: (string) ($request->request->get('name') ?? ''),
        );
    }
}

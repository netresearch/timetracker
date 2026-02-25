<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

final readonly class IdDto
{
    public function __construct(
        public int $id = 0,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            id: (int) ($request->request->get('id') ?? 0),
        );
    }
}

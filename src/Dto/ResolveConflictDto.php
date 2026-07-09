<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of POST /api/v2/worklog-sync/conflicts/{id}/resolve (ADR-023
 * §6): which side wins the parked conflict.
 */
final readonly class ResolveConflictDto
{
    public function __construct(
        #[Assert\Choice(choices: ['local', 'remote'], message: 'Invalid winner.')]
        public string $winner,
    ) {
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ValueObject\Sync;

use App\Entity\Project;

final readonly class ProjectResolution
{
    public function __construct(
        public ?Project $project,
        public string $reason = '',
    ) {
    }
}

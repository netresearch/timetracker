<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ValueObject\Sync;

use App\Enum\SyncAction;
use App\Enum\WorklogField;

final readonly class ReconciliationDecision
{
    /**
     * @param list<WorklogField> $fields
     */
    public function __construct(
        public SyncAction $action,
        public array $fields = [],
        public string $reason = '',
    ) {
    }
}

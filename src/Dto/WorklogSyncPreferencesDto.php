<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of PUT /api/v2/worklog-sync/preferences (ADR-023 amendment):
 * the caller's own opt-in flags for one connected Jira ticket system.
 * `sync_all` is accepted only from a PL/admin caller and left unchanged when
 * omitted (null).
 */
final readonly class WorklogSyncPreferencesDto
{
    public function __construct(
        #[Assert\Positive]
        public int $ticket_system_id,
        public bool $sync_enabled,
        public ?bool $sync_all = null,
    ) {
    }
}

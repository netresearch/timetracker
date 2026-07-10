<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of POST /api/v2/worklog-sync/runs (ADR-023 §6): which run to
 * start against which ticket system, with an optional date range, user
 * filter (import), and cursor override (sync).
 */
final readonly class WorklogSyncRunDto
{
    /**
     * @param list<string> $users
     */
    public function __construct(
        #[Assert\Choice(choices: ['verify', 'import', 'sync'], message: 'Invalid run type.')]
        public string $type,
        #[Assert\Positive]
        public int $ticket_system_id,
        public ?string $from = null,
        public ?string $to = null,
        #[Assert\All([new Assert\Type('string')])]
        public array $users = [],
        #[Assert\Positive]
        public ?int $default_activity_id = null,
        public bool $dry_run = false,
        public ?string $since = null,
    ) {
    }
}

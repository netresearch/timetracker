<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * The field set a Jira worklog and a TT entry share; only these participate in conflict detection.
 */
enum WorklogField: string
{
    case ISSUE_KEY = 'issue_key';
    case STARTED = 'started';
    case DURATION = 'duration';
    case COMMENT = 'comment';
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Exception\Integration\Jira;

/**
 * The user needs to authorize in Jira first and get an OAuth token.
 */
class JiraApiUnauthorizedException extends JiraApiException
{
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Jira;

/**
 * Issue keys from a JQL search, with an explicit truncation flag — no silent caps (ADR-023).
 */
final readonly class JiraIssueKeySearchResult
{
    /**
     * @param list<string> $keys
     */
    public function __construct(
        public array $keys,
        public bool $truncated,
    ) {
    }
}

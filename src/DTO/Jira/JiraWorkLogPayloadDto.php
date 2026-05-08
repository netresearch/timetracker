<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Jira;

/**
 * Strongly-typed payload for the Jira "Add/Update Worklog" REST endpoints.
 *
 * The Jira worklog API expects a stable JSON shape with the three fields
 * modelled here. Encapsulating them in a DTO keeps the value object immutable,
 * documents the contract, and replaces an opaque `array<string, mixed>` flow
 * inside JiraWorkLogService.
 *
 * @see https://docs.atlassian.com/software/jira/docs/api/REST/latest/#api/2/issue-addWorklog
 */
final readonly class JiraWorkLogPayloadDto
{
    public function __construct(
        public string $comment,
        public string $started,
        public int $timeSpentSeconds,
    ) {
    }

    /**
     * Serialises the DTO to the array shape expected by the Jira REST API
     * and by the application's HTTP client (which JSON-encodes the payload).
     *
     * @return array{comment: string, started: string, timeSpentSeconds: int}
     */
    public function toArray(): array
    {
        return [
            'comment' => $this->comment,
            'started' => $this->started,
            'timeSpentSeconds' => $this->timeSpentSeconds,
        ];
    }
}

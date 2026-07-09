<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Jira;

use function is_array;
use function is_numeric;
use function is_object;

/**
 * One page of Jira's worklog/updated or worklog/deleted feed (ADR-023 §5 read path 1).
 */
final readonly class JiraWorklogFeedPage
{
    /**
     * @param list<int> $worklogIds
     */
    public function __construct(
        public array $worklogIds,
        public int $until,
        public bool $lastPage,
    ) {
    }

    public static function fromApiResponse(object $response): self
    {
        /** @var array<string, mixed> $data */
        $data = (array) $response;

        $ids = [];
        if (isset($data['values']) && is_array($data['values'])) {
            foreach ($data['values'] as $value) {
                if (is_object($value) && isset($value->worklogId) && is_numeric($value->worklogId)) {
                    $ids[] = (int) $value->worklogId;
                }
            }
        }

        return new self(
            worklogIds: $ids,
            until: isset($data['until']) && is_numeric($data['until']) ? (int) $data['until'] : 0,
            lastPage: !isset($data['lastPage']) || true === $data['lastPage'],
        );
    }
}

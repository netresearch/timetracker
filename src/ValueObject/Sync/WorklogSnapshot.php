<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ValueObject\Sync;

use App\Enum\WorklogField;
use InvalidArgumentException;

use function is_int;
use function is_string;

/**
 * Normalized projection of a worklog — the shared TT↔Jira field set (ADR-023 §2).
 * Producers (projector/normalizer) apply all normalization; comparison here is exact.
 */
final readonly class WorklogSnapshot
{
    public function __construct(
        public string $issueKey,
        public int $startedTimestamp,
        public int $durationMinutes,
        public string $comment,
    ) {
    }

    public function equals(self $other): bool
    {
        return [] === $this->diff($other);
    }

    /**
     * @return list<WorklogField>
     */
    public function diff(self $other): array
    {
        $fields = [];
        if ($this->issueKey !== $other->issueKey) {
            $fields[] = WorklogField::ISSUE_KEY;
        }

        if ($this->startedTimestamp !== $other->startedTimestamp) {
            $fields[] = WorklogField::STARTED;
        }

        if ($this->durationMinutes !== $other->durationMinutes) {
            $fields[] = WorklogField::DURATION;
        }

        if ($this->comment !== $other->comment) {
            $fields[] = WorklogField::COMMENT;
        }

        return $fields;
    }

    /**
     * @return array{issue_key: string, started_ts: int, duration_minutes: int, comment: string}
     */
    public function toArray(): array
    {
        return [
            'issue_key' => $this->issueKey,
            'started_ts' => $this->startedTimestamp,
            'duration_minutes' => $this->durationMinutes,
            'comment' => $this->comment,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['issue_key'], $data['started_ts'], $data['duration_minutes'], $data['comment'])
            || !is_string($data['issue_key']) || !is_int($data['started_ts'])
            || !is_int($data['duration_minutes']) || !is_string($data['comment'])
        ) {
            throw new InvalidArgumentException('Invalid worklog snapshot payload');
        }

        return new self($data['issue_key'], $data['started_ts'], $data['duration_minutes'], $data['comment']);
    }
}

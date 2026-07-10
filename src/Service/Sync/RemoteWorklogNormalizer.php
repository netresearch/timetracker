<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

use function intdiv;

/**
 * Normalizes a Jira worklog into the shared field set (ADR-023 §2):
 * offset-aware timestamp, seconds rounded half-up to minutes, normalized comment.
 */
class RemoteWorklogNormalizer
{
    public function normalize(JiraWorkLog $jiraWorkLog, string $issueKey): WorklogSnapshot
    {
        if (null === $jiraWorkLog->started || '' === $jiraWorkLog->started) {
            throw new InvalidArgumentException('Jira worklog ' . ($jiraWorkLog->id ?? 0) . ' has no started timestamp');
        }

        try {
            $started = new DateTimeImmutable($jiraWorkLog->started);
        } catch (Exception $exception) {
            throw new InvalidArgumentException('Unparseable started timestamp: ' . $jiraWorkLog->started, 0, $exception);
        }

        return new WorklogSnapshot(
            issueKey: $issueKey,
            startedTimestamp: $started->getTimestamp(),
            durationMinutes: intdiv(($jiraWorkLog->timeSpentSeconds ?? 0) + 30, 60),
            comment: WorklogCommentCodec::decode($jiraWorkLog->comment ?? ''),
        );
    }
}

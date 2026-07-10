<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * ADR-023 shared remote read: collects a token's Jira worklogs matching an author predicate,
 * within a date range, keyed by worklog id. Used for self reads and PO/per-author reads alike.
 *
 * Notices are reported through the caller's `$onNotice` callback rather than owning any run
 * state: `('truncated')` when the issue search was capped, `('error', $issueKey, $throwable)`
 * when an issue's worklogs could not be fetched, and
 * `('error', $issueKey, $throwable, $worklogId)` when a worklog could not be normalized.
 */
class RemoteWorklogReader
{
    public function __construct(
        private readonly RemoteWorklogNormalizer $remoteWorklogNormalizer,
    ) {
    }

    /**
     * @param callable(JiraWorkLog): bool                          $matchesAuthor
     * @param callable(string, ?string=, ?Throwable=, ?int=): void $onNotice
     *
     * @return array<int, array{snapshot: WorklogSnapshot, updated: ?string, author: ?string, issueKey: string}>
     */
    public function readForAuthor(
        JiraOAuthApiService $api,
        callable $matchesAuthor,
        string $jql,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        callable $onNotice,
    ): array {
        $searchResult = $api->searchIssueKeysWithWorklogs($jql);
        if ($searchResult->truncated) {
            $onNotice('truncated');
        }

        $rangeFrom = $from->setTime(0, 0)->getTimestamp();
        $rangeTo = $to->setTime(23, 59, 59)->getTimestamp();

        $remoteByWorklogId = [];
        foreach ($searchResult->keys as $issueKey) {
            try {
                $issueWorklogs = $api->getIssueWorklogs($issueKey);
            } catch (Throwable $throwable) {
                $onNotice('error', $issueKey, $throwable);
                continue;
            }

            foreach ($issueWorklogs as $jiraWorkLog) {
                $record = $this->normalizeWorklog($jiraWorkLog, $issueKey, $matchesAuthor, $rangeFrom, $rangeTo, $onNotice);
                if (null !== $record && null !== $jiraWorkLog->id) {
                    $remoteByWorklogId[$jiraWorkLog->id] = $record;
                }
            }
        }

        return $remoteByWorklogId;
    }

    /**
     * @param callable(JiraWorkLog): bool                          $matchesAuthor
     * @param callable(string, ?string=, ?Throwable=, ?int=): void $onNotice
     *
     * @return array{snapshot: WorklogSnapshot, updated: ?string, author: ?string, issueKey: string}|null
     */
    private function normalizeWorklog(
        JiraWorkLog $jiraWorkLog,
        string $issueKey,
        callable $matchesAuthor,
        int $rangeFrom,
        int $rangeTo,
        callable $onNotice,
    ): ?array {
        if (null === $jiraWorkLog->id) {
            return null;
        }

        if (!$matchesAuthor($jiraWorkLog)) {
            return null;
        }

        try {
            $snapshot = $this->remoteWorklogNormalizer->normalize($jiraWorkLog, $issueKey);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $onNotice('error', $issueKey, $invalidArgumentException, $jiraWorkLog->id);

            return null;
        }

        if ($snapshot->startedTimestamp < $rangeFrom || $snapshot->startedTimestamp > $rangeTo) {
            return null;
        }

        return [
            'snapshot' => $snapshot,
            'updated' => $jiraWorkLog->updated,
            'author' => $jiraWorkLog->authorAccountId ?? $jiraWorkLog->authorName,
            'issueKey' => $issueKey,
        ];
    }
}

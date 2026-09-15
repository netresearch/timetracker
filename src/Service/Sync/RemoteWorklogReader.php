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
     * Reads one worklog straight from the issue its entry names, bypassing both the issue search
     * and the date range. The range is what makes this necessary: a worklog re-dated out of the
     * window is missing from readForAuthor() while still existing in Jira, and an absence there
     * is otherwise read as a deletion.
     *
     * Null means Jira no longer has that worklog on that issue — deleted, or moved to another
     * issue, which the caller's own move detection handles. A failed read is reported as an
     * `('error', $issueKey, $throwable, $worklogId)` notice and also yields null, so the caller
     * treats the worklog as unverified rather than gone.
     *
     * Unlike readForAuthor() this applies no author predicate, deliberately: the caller asks by
     * worklog id, which is the identity of one worklog in the whole instance, and a worklog whose
     * author changed in Jira still exists — dropping it here would send its entry back down the
     * deletion path, which is the outcome this read exists to prevent.
     *
     * @param callable(string, ?string=, ?Throwable=, ?int=): void $onNotice
     *
     * @return array{snapshot: WorklogSnapshot, updated: ?string, author: ?string, issueKey: string}|null
     */
    public function readOne(JiraOAuthApiService $api, string $issueKey, int $worklogId, callable $onNotice): ?array
    {
        try {
            $jiraWorkLog = $api->getIssueWorklog($issueKey, $worklogId);
        } catch (Throwable $throwable) {
            $onNotice('error', $issueKey, $throwable, $worklogId);

            return null;
        }

        if (!$jiraWorkLog instanceof JiraWorkLog) {
            return null;
        }

        return $this->toRecord($jiraWorkLog, $worklogId, $issueKey, $onNotice);
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

        $record = $this->toRecord($jiraWorkLog, $jiraWorkLog->id, $issueKey, $onNotice);
        if (null === $record) {
            return null;
        }

        if ($record['snapshot']->startedTimestamp < $rangeFrom || $record['snapshot']->startedTimestamp > $rangeTo) {
            return null;
        }

        return $record;
    }

    /**
     * Normalizes one worklog into the shared record shape, reporting an unusable one as a notice.
     *
     * @param callable(string, ?string=, ?Throwable=, ?int=): void $onNotice
     *
     * @return array{snapshot: WorklogSnapshot, updated: ?string, author: ?string, issueKey: string}|null
     */
    private function toRecord(JiraWorkLog $jiraWorkLog, int $worklogId, string $issueKey, callable $onNotice): ?array
    {
        try {
            $snapshot = $this->remoteWorklogNormalizer->normalize($jiraWorkLog, $issueKey);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $onNotice('error', $issueKey, $invalidArgumentException, $worklogId);

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

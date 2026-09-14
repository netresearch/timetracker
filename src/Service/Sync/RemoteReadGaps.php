<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

/**
 * What a remote worklog read could not see (ADR-023 sync and verify): issues whose worklogs
 * could not be fetched, worklogs that could not be normalized, and a capped issue search.
 *
 * A linked worklog missing from the remote set is evidence of a deletion — or of a move — only
 * where none of these gaps can hide it. Fed from the RemoteWorklogReader notices of one read.
 */
final class RemoteReadGaps
{
    private bool $searchTruncated = false;

    /** @var array<string, true> */
    private array $unreadableIssueKeys = [];

    /** @var array<int, true> */
    private array $unreadableWorklogIds = [];

    /**
     * Records one reader notice: `('truncated')`, `('error', $issueKey)` for an issue whose
     * worklogs could not be fetched, or `('error', $issueKey, $worklogId)` for a worklog that
     * could not be normalized.
     */
    public function record(string $type, ?string $issueKey = null, ?int $worklogId = null): void
    {
        if ('truncated' === $type) {
            $this->searchTruncated = true;

            return;
        }

        if (null !== $worklogId) {
            $this->unreadableWorklogIds[$worklogId] = true;

            return;
        }

        if (null !== $issueKey) {
            $this->unreadableIssueKeys[$issueKey] = true;
        }
    }

    /**
     * Whether a linked worklog missing from the read may be concluded deleted in Jira.
     *
     * A worklog can move between issues, so an issue that could not be read — or one beyond
     * the search cap — may hold any missing worklog: those gaps block every conclusion. A
     * worklog that could not be normalized blocks only itself.
     */
    public function allowsDeletionOf(int $worklogId): bool
    {
        return !$this->searchTruncated
            && [] === $this->unreadableIssueKeys
            && !isset($this->unreadableWorklogIds[$worklogId]);
    }

    /**
     * Whether a linked entry may be relinked to another worklog read with its start and
     * duration. That needs its own worklog to be known gone from where it was booked: the
     * entry's issue was read, the search was complete, and the worklog was not dropped as
     * unreadable. Otherwise the "moved" worklog may just be a second booking.
     */
    public function allowsRelinkOf(int $worklogId, string $issueKey): bool
    {
        return !$this->searchTruncated
            && !isset($this->unreadableIssueKeys[$issueKey])
            && !isset($this->unreadableWorklogIds[$worklogId]);
    }
}

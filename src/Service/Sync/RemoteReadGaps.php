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

    private bool $issueUnreadable = false;

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

        $this->issueUnreadable = true;
    }

    /**
     * Whether the absence of a linked worklog from the read may be acted on: concluded deleted
     * in Jira, or concluded moved to a lookalike worklog.
     *
     * Worklogs move between issues and issues get renamed, so an issue that could not be read —
     * or one beyond the search cap — may hold any missing worklog, whatever issue key its entry
     * still stores: those gaps block every conclusion in the run. A worklog Jira returned but
     * that could not be normalized blocks only itself.
     */
    public function allowsConclusionAbout(int $worklogId): bool
    {
        return !$this->searchTruncated
            && !$this->issueUnreadable
            && !isset($this->unreadableWorklogIds[$worklogId]);
    }
}

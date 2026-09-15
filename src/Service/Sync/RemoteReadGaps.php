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
 * where none of these gaps can hide it. Fed from the RemoteWorklogReader notices of one read;
 * the caller then claims the worklog ids local entries own (see claim()).
 */
final class RemoteReadGaps
{
    private bool $searchTruncated = false;

    private bool $issueUnreadable = false;

    /** @var array<int, true> */
    private array $unreadableWorklogIds = [];

    /** @var array<int, true> */
    private array $claimedWorklogIds = [];

    /**
     * Records one reader notice: `('truncated')`, `('error')` for an issue whose worklogs could
     * not be fetched, or `('error', $worklogId)` for a worklog that could not be normalized.
     */
    public function record(string $type, ?int $worklogId = null): void
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
     * Declares that a local entry holds this worklog id, so the worklog is where that entry says
     * it is. An unreadable worklog nobody claims is of unknown issue, start and duration, so it
     * may be the recreated target of a move and blocks the run until it is claimed.
     */
    public function claim(int $worklogId): void
    {
        $this->claimedWorklogIds[$worklogId] = true;
    }

    /**
     * The unreadable worklog ids no local entry has claimed yet — what the caller still has to
     * look up before absences may be acted on.
     *
     * @return list<int>
     */
    public function unclaimedUnreadableWorklogIds(): array
    {
        return array_values(array_filter(
            array_keys($this->unreadableWorklogIds),
            fn (int $worklogId): bool => !isset($this->claimedWorklogIds[$worklogId]),
        ));
    }

    /**
     * Whether the absence of a linked worklog from the read may be acted on: concluded deleted
     * in Jira, or concluded moved to a lookalike worklog.
     *
     * Worklogs move between issues and issues get renamed, so an issue that could not be read —
     * or one beyond the search cap — may hold any missing worklog, whatever issue key its entry
     * still stores: those gaps block every conclusion in the run. A worklog Jira returned but
     * that could not be normalized blocks only itself, once a local entry claims it.
     */
    public function allowsConclusionAbout(int $worklogId): bool
    {
        return !$this->hidesMoves()
            && !isset($this->unreadableWorklogIds[$worklogId]);
    }

    /**
     * Whether a missing worklog may have moved somewhere the read could not see — an issue that
     * could not be read, one beyond the search cap, or an unreadable worklog no local entry
     * claims. A claimed worklog Jira returned but could not normalize sits where its entry says,
     * so it is neither the source nor the target of a move; an unclaimed one could be either.
     */
    public function hidesMoves(): bool
    {
        return $this->searchTruncated
            || $this->issueUnreadable
            || [] !== $this->unclaimedUnreadableWorklogIds();
    }
}

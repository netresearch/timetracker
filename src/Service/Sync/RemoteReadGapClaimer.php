<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Repository\EntryRepository;

use function array_keys;

/**
 * Tells a RemoteReadGaps which worklog ids local entries hold, so an unreadable worklog that
 * sits where an entry says it does stops blocking the run (ADR-023 sync and verify).
 */
final readonly class RemoteReadGapClaimer
{
    public function __construct(private EntryRepository $entryRepository)
    {
    }

    /**
     * @param list<Entry> $entries the run's candidates
     */
    public function claimOwned(RemoteReadGaps $gaps, array $entries, TicketSystem $ticketSystem): void
    {
        foreach ($entries as $entry) {
            $worklogId = $entry->getWorklogId();
            if (null !== $worklogId && $worklogId > 0) {
                $gaps->claim($worklogId);
            }
        }

        // Entries outside the run's window own worklogs too, and an unreadable one of theirs is
        // no more a move target than a candidate's: one lookup keeps it from blocking the run.
        $unclaimed = $gaps->unclaimedUnreadableWorklogIds();
        if ([] === $unclaimed) {
            return;
        }

        foreach (array_keys($this->entryRepository->findByWorklogIdsAndTicketSystem($unclaimed, $ticketSystem)) as $worklogId) {
            $gaps->claim($worklogId);
        }
    }
}

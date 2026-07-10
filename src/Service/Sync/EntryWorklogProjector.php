<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Entry;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTime;

/**
 * Projects a TT entry into the shared worklog field set for reconciliation (ADR-023 §2).
 * The comparable "comment" is the entry's bare description — the value a Jira worklog
 * comment decodes to — so TT-pushed and Jira-native worklogs compare cleanly. The
 * `#<id>: <activity>:` wrapper the push adds is a write-format concern, not a
 * reconciliation field (activity is TT-only and never participates in the diff).
 */
class EntryWorklogProjector
{
    public function project(Entry $entry): WorklogSnapshot
    {
        $started = DateTime::createFromInterface($entry->getDay());
        $started->setTime(
            (int) $entry->getStart()->format('H'),
            (int) $entry->getStart()->format('i'),
        );

        return new WorklogSnapshot(
            issueKey: $entry->getTicket(),
            startedTimestamp: $started->getTimestamp(),
            durationMinutes: $entry->getDuration(),
            comment: WorklogCommentCodec::normalize($entry->getDescription()),
        );
    }
}

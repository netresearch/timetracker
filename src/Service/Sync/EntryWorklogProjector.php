<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Activity;
use App\Entity\Entry;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTime;

/**
 * Projects a TT entry into the shared worklog field set, mirroring exactly what
 * the production push (JiraOAuthApiService) would write to Jira (ADR-023 §2).
 */
class EntryWorklogProjector
{
    public function __construct(private readonly WorklogCommentCodec $worklogCommentCodec)
    {
    }

    public function project(Entry $entry): WorklogSnapshot
    {
        $started = DateTime::createFromInterface($entry->getDay());
        $started->setTime(
            (int) $entry->getStart()->format('H'),
            (int) $entry->getStart()->format('i'),
        );

        $activity = $entry->getActivity();

        $comment = $this->worklogCommentCodec->encode(
            $entry->getId(),
            $activity instanceof Activity ? $activity->getName() : null,
            $entry->getDescription(),
        );

        return new WorklogSnapshot(
            issueKey: $entry->getTicket(),
            startedTimestamp: $started->getTimestamp(),
            durationMinutes: $entry->getDuration(),
            comment: WorklogCommentCodec::normalize($comment),
        );
    }
}

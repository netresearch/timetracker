<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Activity;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;

/**
 * Per-run parameters and accumulating state of one import run (ADR-023 Phase 2).
 * The readonly constructor properties are the run's scope; the public array
 * properties accumulate while the run processes worklogs.
 */
final class ImportRunContext
{
    /** @var array<string, ?User> author lookup cache; null = looked up and unknown */
    public array $authorCache = [];

    /** @var array<string, true> remote keys whose shadow creation was already announced */
    public array $shadowAnnounced = [];

    /** @var array<string, true> issue keys whose unresolved project was already announced */
    public array $unresolvedAnnounced = [];

    /** @var array<string, array{user: User, day: string}> (user, day) pairs needing day-class recalculation */
    public array $affectedDays = [];

    public int $createdSinceFlush = 0;

    /**
     * @param list<string> $targetUsernames empty = import for all mapped/creatable authors
     */
    public function __construct(
        public readonly SyncRun $syncRun,
        public readonly TicketSystem $ticketSystem,
        public readonly Activity $activity,
        public readonly array $targetUsernames,
        public readonly bool $dryRun,
        public readonly int $rangeFrom,
        public readonly int $rangeTo,
    ) {
    }
}

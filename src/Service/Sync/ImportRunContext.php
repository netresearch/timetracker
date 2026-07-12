<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
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

    /**
     * Per-run, per-prefix ad-hoc auto-create result (ADR-026 P3): a prefix is
     * derived once (Tempo/Jira calls are expensive) and both outcomes cache —
     * the created Project and the parked null. Keyed by Jira prefix; use
     * array_key_exists to tell "cached null" from "not yet derived".
     *
     * @var array<string, ?Project>
     */
    public array $autoImportCache = [];

    /** @var array<string, Customer> run-level Tempo-key reuse for P3 auto-created customers (avoids a UNIQUE-key clash on an unflushed key) */
    public array $customersByTempoKey = [];

    /** @var array<string, Customer> run-level name reuse for P3 auto-created customers */
    public array $customersByName = [];

    /** @var array<string, array{user: User, day: string}> (user, day) pairs needing day-class recalculation */
    public array $affectedDays = [];

    public int $createdSinceFlush = 0;

    /**
     * @param list<string> $targetUsernames empty = import for all mapped/creatable authors
     */
    public function __construct(
        public readonly SyncRun $syncRun,
        public readonly User $triggeredBy,
        public readonly TicketSystem $ticketSystem,
        public readonly Activity $activity,
        public readonly array $targetUsernames,
        public readonly bool $dryRun,
        public readonly int $rangeFrom,
        public readonly int $rangeTo,
    ) {
    }
}

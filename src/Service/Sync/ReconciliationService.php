<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Enum\SyncAction;
use App\Enum\WorklogField;
use App\ValueObject\Sync\ReconciliationDecision;
use App\ValueObject\Sync\WorklogSnapshot;

use function array_column;
use function array_intersect;
use function array_map;
use function array_values;

/**
 * The ADR-023 §2 decision matrix. Pure: no I/O, no persistence, fully unit-testable.
 */
class ReconciliationService
{
    public function reconcile(?WorklogSnapshot $base, ?WorklogSnapshot $local, ?WorklogSnapshot $remote): ReconciliationDecision
    {
        if (null === $local && null === $remote) {
            return new ReconciliationDecision(SyncAction::NONE, [], 'nothing on either side');
        }

        if (null === $local) {
            return new ReconciliationDecision(SyncAction::CREATE_LOCAL, [], 'remote worklog has no matching entry');
        }

        if (null === $remote) {
            return new ReconciliationDecision(SyncAction::REMOTE_MISSING, [], 'linked remote worklog not found');
        }

        if (null === $base) {
            $diff = $local->diff($remote);
            if ([] === $diff) {
                return new ReconciliationDecision(SyncAction::NONE, [], 'equal without base');
            }

            return new ReconciliationDecision(SyncAction::DIVERGED, $diff, 'differs but no base to attribute the change');
        }

        $localDiff = $base->diff($local);
        $remoteDiff = $base->diff($remote);

        if ([] === $localDiff && [] === $remoteDiff) {
            return new ReconciliationDecision(SyncAction::NONE, [], 'in sync');
        }

        if ([] === $remoteDiff) {
            return new ReconciliationDecision(SyncAction::PUSH, $localDiff, 'local changed since base');
        }

        if ([] === $localDiff) {
            return new ReconciliationDecision(SyncAction::PULL, $remoteDiff, 'remote changed since base');
        }

        if ($local->equals($remote)) {
            return new ReconciliationDecision(SyncAction::NONE, [], 'both sides changed identically; base is stale');
        }

        $overlap = array_values(array_intersect(
            array_column($localDiff, 'value'),
            array_column($remoteDiff, 'value'),
        ));

        if ([] !== $overlap) {
            $fields = array_values(array_map(WorklogField::from(...), $overlap));

            return new ReconciliationDecision(SyncAction::CONFLICT, $fields, 'both sides changed the same field(s)');
        }

        $union = [];
        foreach ([...$localDiff, ...$remoteDiff] as $field) {
            $union[$field->value] = $field;
        }

        return new ReconciliationDecision(SyncAction::MERGE, array_values($union), 'both dirty on disjoint fields');
    }
}

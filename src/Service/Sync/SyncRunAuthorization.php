<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\SyncRun;
use App\Entity\User;
use App\Entity\WorklogSyncState;

/**
 * The ADR-023 §6 authorization matrix (amended), shared by the v2 actions and
 * the MCP tools: admins may do anything; non-admins may verify themselves,
 * import only their own username, sync only themselves, and see/resolve only
 * their own runs and entries.
 */
final readonly class SyncRunAuthorization
{
    /**
     * May $user start a run of $type against $users?
     *
     * @param list<string> $users usernames a run targets (import), or the
     *                            single target of a sync in $users[0]
     */
    public function canTrigger(User $user, bool $isAdmin, string $type, array $users): bool
    {
        if ($isAdmin) {
            return true;
        }

        if ('sync' === $type) {
            // A manual self-sync is always allowed (the opt-in flag governs the
            // cron, not manual triggers); syncing another target needs PL/ADMIN.
            return [] === $users || [$user->getUsername()] === $users;
        }

        if ('import' === $type) {
            return [$user->getUsername()] === $users;
        }

        return true;
    }

    /**
     * May $user read $syncRun? Non-admins see only runs they triggered.
     */
    public function canSeeRun(User $user, bool $isAdmin, SyncRun $syncRun): bool
    {
        return $isAdmin || $syncRun->getTriggeredBy()?->getId() === $user->getId();
    }

    /**
     * May $user resolve $state? Non-admins only own the conflicts on their
     * own entries.
     */
    public function canResolve(User $user, bool $isAdmin, WorklogSyncState $state): bool
    {
        return $isAdmin || $state->getEntry()?->getUser()?->getId() === $user->getId();
    }
}

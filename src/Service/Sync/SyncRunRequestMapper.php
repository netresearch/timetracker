<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Dto\WorklogSyncRunDto;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

use function trim;

/**
 * Maps a worklog sync run request onto the engine (ADR-023 §6, amended):
 * parses the date range and dispatches to the verify/import/sync service.
 * Shared by the v2 create action and the MCP sync tool so the parsing and
 * dispatch logic exists exactly once. A sync run targets a single user under
 * the caller's own token — the author when they self-sync, or a PO acting on a
 * named target.
 */
final readonly class SyncRunRequestMapper
{
    public function __construct(
        private VerifyWorklogsService $verifyWorklogsService,
        private ImportWorklogsService $importWorklogsService,
        private SyncWorklogsService $syncWorklogsService,
    ) {
    }

    /**
     * Date range with defaults: first day of the current month to today.
     *
     * @throws Exception on malformed dates
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function parseRange(WorklogSyncRunDto $worklogSyncRunDto): array
    {
        // DateTimeImmutable('') and whitespace-only both silently resolve to "now" — reject.
        if ((null !== $worklogSyncRunDto->from && '' === trim($worklogSyncRunDto->from))
            || (null !== $worklogSyncRunDto->to && '' === trim($worklogSyncRunDto->to))
        ) {
            throw new InvalidArgumentException('Blank date in from/to');
        }

        $from = null !== $worklogSyncRunDto->from ? new DateTimeImmutable($worklogSyncRunDto->from) : new DateTimeImmutable('first day of this month');
        $to = null !== $worklogSyncRunDto->to ? new DateTimeImmutable($worklogSyncRunDto->to) : new DateTimeImmutable('today');

        return [$from, $to];
    }

    /**
     * Start the requested run inline and return the finished SyncRun. A sync
     * run always runs under $user's token: it targets $syncTarget when given
     * (a PO acting on a named user), otherwise $user themselves (self-sync).
     */
    public function dispatch(
        WorklogSyncRunDto $worklogSyncRunDto,
        User $user,
        TicketSystem $ticketSystem,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?User $syncTarget = null,
    ): SyncRun {
        return match ($worklogSyncRunDto->type) {
            'verify' => $this->verifyWorklogsService->verify($user, $ticketSystem, $from, $to),
            'import' => $this->importWorklogsService->import(
                $user,
                $ticketSystem,
                $from,
                $to,
                (int) $worklogSyncRunDto->default_activity_id,
                $worklogSyncRunDto->users,
                $worklogSyncRunDto->dry_run,
            ),
            default => $this->syncWorklogsService->syncUser($syncTarget ?? $user, $user, $ticketSystem, $from, $to, $worklogSyncRunDto->dry_run),
        };
    }
}

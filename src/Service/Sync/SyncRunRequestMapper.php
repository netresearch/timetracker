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

use function ctype_digit;

/**
 * Maps a worklog sync run request onto the engine (ADR-023 §6): parses the
 * date range and cursor override, and dispatches to the verify/import/sync
 * service. Shared by the v2 create action and the MCP sync tool so the
 * parsing and dispatch logic exists exactly once.
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
        // DateTimeImmutable('') silently resolves to "now" — reject it as malformed.
        if ('' === $worklogSyncRunDto->from || '' === $worklogSyncRunDto->to) {
            throw new InvalidArgumentException('Empty date in from/to');
        }

        $from = null !== $worklogSyncRunDto->from ? new DateTimeImmutable($worklogSyncRunDto->from) : new DateTimeImmutable('first day of this month');
        $to = null !== $worklogSyncRunDto->to ? new DateTimeImmutable($worklogSyncRunDto->to) : new DateTimeImmutable('today');

        return [$from, $to];
    }

    /**
     * Cursor override for sync runs — Y-m-d or epoch milliseconds, like the
     * tt:sync-worklogs command's --since option.
     *
     * @throws Exception on a malformed date string
     */
    public function parseSince(WorklogSyncRunDto $worklogSyncRunDto): ?int
    {
        if ('sync' !== $worklogSyncRunDto->type || null === $worklogSyncRunDto->since) {
            return null;
        }

        if ('' === $worklogSyncRunDto->since) {
            throw new InvalidArgumentException('Empty since value');
        }

        if (ctype_digit($worklogSyncRunDto->since)) {
            return (int) $worklogSyncRunDto->since;
        }

        return new DateTimeImmutable($worklogSyncRunDto->since)->getTimestamp() * 1000;
    }

    /**
     * Start the requested run inline and return the finished SyncRun.
     */
    public function dispatch(
        WorklogSyncRunDto $worklogSyncRunDto,
        User $user,
        TicketSystem $ticketSystem,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $sinceMillis,
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
            default => $this->syncWorklogsService->sync($ticketSystem, $sinceMillis, $worklogSyncRunDto->dry_run),
        };
    }
}

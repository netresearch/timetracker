<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Personio;

use App\Entity\SyncRun;
use App\Entity\User;
use DateTimeImmutable;
use Exception;

/**
 * Shared entry point for triggering the Personio export/import on demand (ADR-024
 * P3 API/MCP triggers), so the v2 API action and the MCP tool share one code path.
 *
 * `allUsers` runs the cron path (every opted-in, mapped user); otherwise it runs
 * for the single caller. The caller is responsible for the admin check behind
 * `allUsers` — this service only dispatches. Date parsing throws so the caller can
 * report a clean validation error.
 */
final readonly class PersonioRunTrigger
{
    /** The two run directions the API/MCP triggers accept. */
    public const string DIRECTION_EXPORT = 'export';

    public const string DIRECTION_IMPORT = 'import';

    public function __construct(
        private AttendanceExportService $attendanceExportService,
        private AbsenceImportService $absenceImportService,
    ) {
    }

    /**
     * Dispatch by direction so the API action and MCP tool stay a single call.
     * `import` ignores $dryRun (the absence import has no preview mode).
     *
     * @throws Exception on an unparseable from/to date
     *
     * @return list<SyncRun>
     */
    public function run(string $direction, User $user, bool $allUsers, ?string $from, ?string $to, bool $dryRun): array
    {
        return self::DIRECTION_IMPORT === $direction
            ? $this->import($user, $allUsers, $from, $to)
            : $this->export($user, $allUsers, $from, $to, $dryRun);
    }

    /**
     * Whether $direction is a supported run direction.
     */
    public static function isDirection(string $direction): bool
    {
        return self::DIRECTION_EXPORT === $direction || self::DIRECTION_IMPORT === $direction;
    }

    /**
     * @throws Exception on an unparseable from/to date
     *
     * @return list<SyncRun>
     */
    public function export(User $user, bool $allUsers, ?string $from, ?string $to, bool $dryRun): array
    {
        // Attendance export window: rolling last 14 days (mirrors the command).
        $fromDate = null !== $from ? new DateTimeImmutable($from) : new DateTimeImmutable('today')->modify('-14 days');
        $toDate = null !== $to ? new DateTimeImmutable($to) : new DateTimeImmutable('today');

        return $allUsers
            ? $this->attendanceExportService->exportAllOptedIn($fromDate, $toDate, $dryRun)
            : [$this->attendanceExportService->exportUser($user, $fromDate, $toDate, $dryRun)];
    }

    /**
     * @throws Exception on an unparseable from/to date
     *
     * @return list<SyncRun>
     */
    public function import(User $user, bool $allUsers, ?string $from, ?string $to): array
    {
        // Absence import window: 30 days back, 90 ahead (mirrors the command).
        $fromDate = null !== $from ? new DateTimeImmutable($from) : new DateTimeImmutable('today')->modify('-30 days');
        $toDate = null !== $to ? new DateTimeImmutable($to) : new DateTimeImmutable('today')->modify('+90 days');

        return $allUsers
            ? $this->absenceImportService->importAllOptedIn($fromDate, $toDate)
            : [$this->absenceImportService->importUser($user, $fromDate, $toDate)];
    }
}

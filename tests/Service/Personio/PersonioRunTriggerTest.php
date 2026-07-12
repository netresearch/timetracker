<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Personio;

use App\Entity\SyncRun;
use App\Entity\User;
use App\Service\Personio\AbsenceImportService;
use App\Service\Personio\AttendanceExportService;
use App\Service\Personio\PersonioRunTrigger;
use DateTimeImmutable;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class PersonioRunTriggerTest extends TestCase
{
    private AttendanceExportService&MockObject $exportService;
    private AbsenceImportService&MockObject $importService;
    private PersonioRunTrigger $trigger;

    protected function setUp(): void
    {
        $this->exportService = $this->createMock(AttendanceExportService::class);
        $this->importService = $this->createMock(AbsenceImportService::class);
        $this->trigger = new PersonioRunTrigger($this->exportService, $this->importService);
    }

    public function testExportSelfCallsExportUser(): void
    {
        $user = new User();
        $this->exportService->expects(self::once())->method('exportUser')
            ->with($user, self::isInstanceOf(DateTimeImmutable::class), self::isInstanceOf(DateTimeImmutable::class), true)
            ->willReturn(new SyncRun());
        $this->exportService->expects(self::never())->method('exportAllOptedIn');

        $runs = $this->trigger->export($user, false, null, null, true);

        self::assertCount(1, $runs);
    }

    public function testExportAllUsersCallsExportAllOptedIn(): void
    {
        $this->exportService->expects(self::once())->method('exportAllOptedIn')
            ->willReturn([new SyncRun(), new SyncRun()]);
        $this->exportService->expects(self::never())->method('exportUser');

        $runs = $this->trigger->export(new User(), true, '2026-07-01', '2026-07-14', false);

        self::assertCount(2, $runs);
    }

    public function testImportSelfCallsImportUser(): void
    {
        $user = new User();
        $this->importService->expects(self::once())->method('importUser')
            ->with($user, self::isInstanceOf(DateTimeImmutable::class), self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn(new SyncRun());
        $this->importService->expects(self::never())->method('importAllOptedIn');

        $runs = $this->trigger->import($user, false, null, null);

        self::assertCount(1, $runs);
    }

    public function testImportAllUsersCallsImportAllOptedIn(): void
    {
        $this->importService->expects(self::once())->method('importAllOptedIn')
            ->willReturn([new SyncRun()]);

        $runs = $this->trigger->import(new User(), true, null, null);

        self::assertCount(1, $runs);
    }

    public function testInvalidDateThrows(): void
    {
        $this->expectException(Exception::class);

        $this->trigger->export(new User(), false, 'not-a-date', null, false);
    }
}

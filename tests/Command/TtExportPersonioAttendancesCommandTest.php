<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Command;

use App\Command\TtExportPersonioAttendancesCommand;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Service\Personio\AttendanceExportService;
use App\Service\Sync\SyncRunConsoleRenderer;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 *
 * @coversNothing
 */
#[AllowMockObjectsWithoutExpectations]
final class TtExportPersonioAttendancesCommandTest extends TestCase
{
    /**
     * @param MockObject&AttendanceExportService $exportService
     */
    private function commandTester(?User $user, MockObject $exportService): CommandTester
    {
        $userRepository = $this->createMock(ObjectRepository::class);
        $userRepository->method('findOneBy')->willReturn($user);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')->willReturn($userRepository);

        return new CommandTester(
            new TtExportPersonioAttendancesCommand($exportService, $managerRegistry, new SyncRunConsoleRenderer()),
        );
    }

    /**
     * @param list<SyncRun> $runs
     *
     * @return MockObject&AttendanceExportService
     */
    private function exportService(?array $runs = null): MockObject
    {
        $exportService = $this->createMock(AttendanceExportService::class);
        if (null !== $runs) {
            $exportService->method('exportAllOptedIn')->willReturn($runs);
        }

        return $exportService;
    }

    /**
     * @param array<string, int> $counters
     */
    private function syncRun(SyncRunStatus $syncRunStatus, array $counters = []): SyncRun
    {
        return new SyncRun()
            ->setType(SyncRunType::PERSONIO_EXPORT)
            ->setStatus($syncRunStatus)
            ->setCounters($counters)
            ->setStartedAt(new DateTimeImmutable('2026-07-09 12:00:00'))
            ->setFinishedAt(new DateTimeImmutable('2026-07-09 12:00:05'));
    }

    public function testExportsAllByDefault(): void
    {
        $exportService = $this->createMock(AttendanceExportService::class);
        $exportService->expects(self::once())->method('exportAllOptedIn')
            ->willReturn([
                $this->syncRun(SyncRunStatus::COMPLETED, ['created' => 3]),
                $this->syncRun(SyncRunStatus::COMPLETED, ['in_sync' => 2]),
            ]);

        $tester = $this->commandTester(null, $exportService);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('created', $tester->getDisplay());
        self::assertStringContainsString('in_sync', $tester->getDisplay());
    }

    public function testExportsSingleUserWithUserOption(): void
    {
        $exportService = $this->createMock(AttendanceExportService::class);
        $exportService->expects(self::once())->method('exportUser')
            ->willReturn($this->syncRun(SyncRunStatus::COMPLETED, ['created' => 1]));
        $exportService->expects(self::never())->method('exportAllOptedIn');

        $tester = $this->commandTester(self::createStub(User::class), $exportService);

        $exitCode = $tester->execute(['--user' => 'alice']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('created', $tester->getDisplay());
    }

    public function testUnknownUserFails(): void
    {
        $tester = $this->commandTester(null, $this->exportService());

        $exitCode = $tester->execute(['--user' => 'ghost']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('User not found', $tester->getDisplay());
    }

    public function testInvalidDateFails(): void
    {
        $tester = $this->commandTester(null, $this->exportService());

        $exitCode = $tester->execute(['--from' => 'nope']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid date', $tester->getDisplay());
    }

    public function testBlankDateFails(): void
    {
        $tester = $this->commandTester(null, $this->exportService());

        $exitCode = $tester->execute(['--to' => '   ']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid date', $tester->getDisplay());
    }

    public function testFailedRunExitsNonZero(): void
    {
        $tester = $this->commandTester(
            null,
            $this->exportService([
                $this->syncRun(SyncRunStatus::COMPLETED),
                $this->syncRun(SyncRunStatus::FAILED),
            ]),
        );

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
    }

    public function testDefaultWindowIsRollingFourteenDays(): void
    {
        $expectedFrom = new DateTimeImmutable('today')->modify('-14 days')->format('Y-m-d');
        $expectedTo = new DateTimeImmutable('today')->format('Y-m-d');

        $exportService = $this->createMock(AttendanceExportService::class);
        $exportService->expects(self::once())->method('exportAllOptedIn')
            ->with(
                self::callback(static fn (DateTimeImmutable $from): bool => $from->format('Y-m-d') === $expectedFrom),
                self::callback(static fn (DateTimeImmutable $to): bool => $to->format('Y-m-d') === $expectedTo),
                false,
            )
            ->willReturn([$this->syncRun(SyncRunStatus::COMPLETED)]);

        $tester = $this->commandTester(null, $exportService);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
    }
}

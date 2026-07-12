<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Command;

use App\Command\TtImportPersonioAbsencesCommand;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Service\Personio\AbsenceImportService;
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
final class TtImportPersonioAbsencesCommandTest extends TestCase
{
    /**
     * @param MockObject&AbsenceImportService $importService
     */
    private function commandTester(?User $user, MockObject $importService): CommandTester
    {
        $userRepository = $this->createMock(ObjectRepository::class);
        $userRepository->method('findOneBy')->willReturn($user);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')->willReturn($userRepository);

        return new CommandTester(
            new TtImportPersonioAbsencesCommand($importService, $managerRegistry, new SyncRunConsoleRenderer()),
        );
    }

    /**
     * @param list<SyncRun> $runs
     *
     * @return MockObject&AbsenceImportService
     */
    private function importService(?array $runs = null): MockObject
    {
        $importService = $this->createMock(AbsenceImportService::class);
        if (null !== $runs) {
            $importService->method('importAllOptedIn')->willReturn($runs);
        }

        return $importService;
    }

    /**
     * @param array<string, int> $counters
     */
    private function syncRun(SyncRunStatus $syncRunStatus, array $counters = []): SyncRun
    {
        return new SyncRun()
            ->setType(SyncRunType::PERSONIO_IMPORT)
            ->setStatus($syncRunStatus)
            ->setCounters($counters)
            ->setStartedAt(new DateTimeImmutable('2026-07-09 12:00:00'))
            ->setFinishedAt(new DateTimeImmutable('2026-07-09 12:00:05'));
    }

    public function testImportsAllByDefault(): void
    {
        $importService = $this->createMock(AbsenceImportService::class);
        $importService->expects(self::once())->method('importAllOptedIn')
            ->willReturn([
                $this->syncRun(SyncRunStatus::COMPLETED, ['imported' => 3]),
                $this->syncRun(SyncRunStatus::COMPLETED, ['in_sync' => 2]),
            ]);

        $tester = $this->commandTester(null, $importService);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('imported', $tester->getDisplay());
        self::assertStringContainsString('in_sync', $tester->getDisplay());
    }

    public function testImportsSingleUserWithUserOption(): void
    {
        $importService = $this->createMock(AbsenceImportService::class);
        $importService->expects(self::once())->method('importUser')
            ->willReturn($this->syncRun(SyncRunStatus::COMPLETED, ['imported' => 1]));
        $importService->expects(self::never())->method('importAllOptedIn');

        $tester = $this->commandTester(self::createStub(User::class), $importService);

        $exitCode = $tester->execute(['--user' => 'alice']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('imported', $tester->getDisplay());
    }

    public function testUnknownUserFails(): void
    {
        $tester = $this->commandTester(null, $this->importService());

        $exitCode = $tester->execute(['--user' => 'ghost']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('User not found', $tester->getDisplay());
    }

    public function testInvalidDateFails(): void
    {
        $tester = $this->commandTester(null, $this->importService());

        $exitCode = $tester->execute(['--from' => 'nope']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid date', $tester->getDisplay());
    }

    public function testFailedRunExitsNonZero(): void
    {
        $tester = $this->commandTester(
            null,
            $this->importService([
                $this->syncRun(SyncRunStatus::COMPLETED),
                $this->syncRun(SyncRunStatus::FAILED),
            ]),
        );

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
    }

    public function testDefaultWindowIsMinus30Plus90(): void
    {
        $expectedFrom = new DateTimeImmutable('today')->modify('-30 days')->format('Y-m-d');
        $expectedTo = new DateTimeImmutable('today')->modify('+90 days')->format('Y-m-d');

        $importService = $this->createMock(AbsenceImportService::class);
        $importService->expects(self::once())->method('importAllOptedIn')
            ->with(
                self::callback(static fn (DateTimeImmutable $from): bool => $from->format('Y-m-d') === $expectedFrom),
                self::callback(static fn (DateTimeImmutable $to): bool => $to->format('Y-m-d') === $expectedTo),
            )
            ->willReturn([$this->syncRun(SyncRunStatus::COMPLETED)]);

        $tester = $this->commandTester(null, $importService);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
    }
}

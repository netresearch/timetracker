<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Command;

use App\Command\TtSyncWorklogsCommand;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Service\Sync\SyncRunConsoleRenderer;
use App\Service\Sync\SyncWorklogsService;
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
final class TtSyncWorklogsCommandTest extends TestCase
{
    /**
     * @param MockObject&SyncWorklogsService $syncService
     */
    private function commandTester(?TicketSystem $ticketSystem, MockObject $syncService): CommandTester
    {
        $ticketSystemRepository = $this->createMock(ObjectRepository::class);
        $ticketSystemRepository->method('find')->willReturn($ticketSystem);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')->willReturn($ticketSystemRepository);

        return new CommandTester(new TtSyncWorklogsCommand($syncService, $managerRegistry, new SyncRunConsoleRenderer()));
    }

    /**
     * @param list<SyncRun> $runs
     *
     * @return MockObject&SyncWorklogsService
     */
    private function syncService(?array $runs = null): MockObject
    {
        $syncService = $this->createMock(SyncWorklogsService::class);
        if (null !== $runs) {
            $syncService->method('syncTicketSystem')->willReturn($runs);
        }

        return $syncService;
    }

    /**
     * @param array<string, int> $counters
     */
    private function syncRun(SyncRunStatus $syncRunStatus, array $counters = []): SyncRun
    {
        return new SyncRun()
            ->setType(SyncRunType::SYNC)
            ->setStatus($syncRunStatus)
            ->setCounters($counters)
            ->setStartedAt(new DateTimeImmutable('2026-07-09 12:00:00'))
            ->setFinishedAt(new DateTimeImmutable('2026-07-09 12:00:05'));
    }

    public function testSyncsTicketSystem(): void
    {
        $syncService = $this->createMock(SyncWorklogsService::class);
        $syncService->expects(self::once())->method('syncTicketSystem')
            ->willReturn([
                $this->syncRun(SyncRunStatus::COMPLETED, ['pushed' => 2, 'pulled' => 1]),
                $this->syncRun(SyncRunStatus::COMPLETED, ['imported' => 3]),
            ]);

        $tester = $this->commandTester(self::createStub(TicketSystem::class), $syncService);

        $exitCode = $tester->execute(['ticket-system-id' => '1']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('pushed', $tester->getDisplay());
        self::assertStringContainsString('imported', $tester->getDisplay());
    }

    public function testUnknownTicketSystemFails(): void
    {
        $tester = $this->commandTester(null, $this->syncService());

        $exitCode = $tester->execute(['ticket-system-id' => '999']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Ticket system not found', $tester->getDisplay());
    }

    public function testInvalidDateFails(): void
    {
        $tester = $this->commandTester(self::createStub(TicketSystem::class), $this->syncService());

        $exitCode = $tester->execute(['ticket-system-id' => '1', '--from' => 'nope']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid date', $tester->getDisplay());
    }

    public function testBlankDateFails(): void
    {
        $tester = $this->commandTester(self::createStub(TicketSystem::class), $this->syncService());

        $exitCode = $tester->execute(['ticket-system-id' => '1', '--to' => '   ']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid date', $tester->getDisplay());
    }

    public function testFailedRunExitsNonZero(): void
    {
        $tester = $this->commandTester(
            self::createStub(TicketSystem::class),
            $this->syncService([
                $this->syncRun(SyncRunStatus::COMPLETED),
                $this->syncRun(SyncRunStatus::FAILED),
            ]),
        );

        $exitCode = $tester->execute(['ticket-system-id' => '1']);

        self::assertSame(1, $exitCode);
    }

    public function testDefaultWindowIsRollingThirtyDays(): void
    {
        $expectedFrom = new DateTimeImmutable('today')->modify('-30 days')->format('Y-m-d');
        $expectedTo = new DateTimeImmutable('today')->format('Y-m-d');

        $syncService = $this->createMock(SyncWorklogsService::class);
        $syncService->expects(self::once())->method('syncTicketSystem')
            ->with(
                self::anything(),
                self::callback(static fn (DateTimeImmutable $from): bool => $from->format('Y-m-d') === $expectedFrom),
                self::callback(static fn (DateTimeImmutable $to): bool => $to->format('Y-m-d') === $expectedTo),
                false,
            )
            ->willReturn([$this->syncRun(SyncRunStatus::COMPLETED)]);

        $tester = $this->commandTester(self::createStub(TicketSystem::class), $syncService);

        $exitCode = $tester->execute(['ticket-system-id' => '1']);

        self::assertSame(0, $exitCode);
    }
}

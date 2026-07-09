<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Command;

use App\Command\TtImportWorklogsCommand;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Service\Sync\ImportWorklogsService;
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
final class TtImportWorklogsCommandTest extends TestCase
{
    /**
     * @param MockObject&ImportWorklogsService $importService
     */
    private function commandTester(?User $user, ?TicketSystem $ticketSystem, MockObject $importService): CommandTester
    {
        $userRepository = $this->createMock(ObjectRepository::class);
        $userRepository->method('findOneBy')->willReturn($user);
        $ticketSystemRepository = $this->createMock(ObjectRepository::class);
        $ticketSystemRepository->method('find')->willReturn($ticketSystem);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')->willReturnCallback(
            static fn (string $className): ObjectRepository => User::class === $className ? $userRepository : $ticketSystemRepository,
        );

        return new CommandTester(new TtImportWorklogsCommand($importService, $managerRegistry, new SyncRunConsoleRenderer()));
    }

    /**
     * @return MockObject&ImportWorklogsService
     */
    private function importService(?SyncRun $syncRun = null): MockObject
    {
        $importService = $this->createMock(ImportWorklogsService::class);
        if (null !== $syncRun) {
            $importService->method('import')->willReturn($syncRun);
        }

        return $importService;
    }

    private function completedRun(): SyncRun
    {
        return new SyncRun()
            ->setType(SyncRunType::IMPORT)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setCounters(['created' => 5, 'probable_duplicate' => 1])
            ->setStartedAt(new DateTimeImmutable('2026-07-09 12:00:00'))
            ->setFinishedAt(new DateTimeImmutable('2026-07-09 12:00:05'));
    }

    public function testRunsImportAndPrintsCounters(): void
    {
        $tester = $this->commandTester(
            self::createStub(User::class),
            self::createStub(TicketSystem::class),
            $this->importService($this->completedRun()),
        );

        $exitCode = $tester->execute([
            'username' => 'po',
            'ticket-system' => '1',
            '--from' => '2026-06-01',
            '--to' => '2026-06-30',
            '--default-activity' => '1',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('created', $tester->getDisplay());
        self::assertStringContainsString('5', $tester->getDisplay());
    }

    public function testMissingDefaultActivityFails(): void
    {
        $tester = $this->commandTester(
            self::createStub(User::class),
            self::createStub(TicketSystem::class),
            $this->importService(),
        );

        $exitCode = $tester->execute(['username' => 'po', 'ticket-system' => '1']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('default-activity', $tester->getDisplay());
    }

    public function testInvalidDateFails(): void
    {
        $tester = $this->commandTester(
            self::createStub(User::class),
            self::createStub(TicketSystem::class),
            $this->importService(),
        );

        $exitCode = $tester->execute([
            'username' => 'po',
            'ticket-system' => '1',
            '--from' => 'nope',
            '--default-activity' => '1',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid date', $tester->getDisplay());
    }

    public function testDryRunFlagIsPassedThrough(): void
    {
        $importService = $this->createMock(ImportWorklogsService::class);
        $importService->expects(self::once())->method('import')
            ->with(self::anything(), self::anything(), self::anything(), self::anything(), 1, [], true)
            ->willReturn($this->completedRun());

        $tester = $this->commandTester(
            self::createStub(User::class),
            self::createStub(TicketSystem::class),
            $importService,
        );

        $exitCode = $tester->execute([
            'username' => 'po',
            'ticket-system' => '1',
            '--default-activity' => '1',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
    }

    public function testUnknownUserFails(): void
    {
        $tester = $this->commandTester(null, self::createStub(TicketSystem::class), $this->importService());

        $exitCode = $tester->execute([
            'username' => 'ghost',
            'ticket-system' => '1',
            '--default-activity' => '1',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('User not found', $tester->getDisplay());
    }
}

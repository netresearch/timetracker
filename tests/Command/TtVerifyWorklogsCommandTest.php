<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Command;

use App\Command\TtVerifyWorklogsCommand;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Service\Sync\SyncRunConsoleRenderer;
use App\Service\Sync\VerifyWorklogsService;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 *
 * @coversNothing
 */
#[AllowMockObjectsWithoutExpectations]
final class TtVerifyWorklogsCommandTest extends TestCase
{
    private function commandTester(?User $user, ?TicketSystem $ticketSystem, ?SyncRun $syncRun = null): CommandTester
    {
        $userRepository = $this->createMock(ObjectRepository::class);
        $userRepository->method('findOneBy')->willReturn($user);
        $ticketSystemRepository = $this->createMock(ObjectRepository::class);
        $ticketSystemRepository->method('find')->willReturn($ticketSystem);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')->willReturnCallback(
            static fn (string $className): ObjectRepository => User::class === $className ? $userRepository : $ticketSystemRepository,
        );

        $verifyService = $this->createMock(VerifyWorklogsService::class);
        if (null !== $syncRun) {
            $verifyService->method('verify')->willReturn($syncRun);
        }

        return new CommandTester(new TtVerifyWorklogsCommand($verifyService, $managerRegistry, new SyncRunConsoleRenderer()));
    }

    private function completedRun(): SyncRun
    {
        return new SyncRun()
            ->setType(SyncRunType::VERIFY)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setCounters(['in_sync' => 3, 'remote_only' => 1])
            ->setStartedAt(new DateTimeImmutable('2026-07-09 12:00:00'))
            ->setFinishedAt(new DateTimeImmutable('2026-07-09 12:00:05'));
    }

    public function testRunsAndPrintsCounters(): void
    {
        $tester = $this->commandTester(self::createStub(User::class), self::createStub(TicketSystem::class), $this->completedRun());

        $exitCode = $tester->execute(['username' => 'jdoe', 'ticket-system' => '1', '--from' => '2026-06-01', '--to' => '2026-06-30']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('in_sync', $tester->getDisplay());
        self::assertStringContainsString('3', $tester->getDisplay());
    }

    public function testUnknownUserFails(): void
    {
        $tester = $this->commandTester(null, self::createStub(TicketSystem::class));

        $exitCode = $tester->execute(['username' => 'ghost', 'ticket-system' => '1']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('User not found', $tester->getDisplay());
    }

    public function testUnknownTicketSystemFails(): void
    {
        $tester = $this->commandTester(self::createStub(User::class), null);

        $exitCode = $tester->execute(['username' => 'jdoe', 'ticket-system' => '99']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Ticket system not found', $tester->getDisplay());
    }

    public function testFailedRunExitsNonZero(): void
    {
        $failedRun = $this->completedRun()->setStatus(SyncRunStatus::FAILED);
        $tester = $this->commandTester(self::createStub(User::class), self::createStub(TicketSystem::class), $failedRun);

        $exitCode = $tester->execute(['username' => 'jdoe', 'ticket-system' => '1']);

        self::assertSame(1, $exitCode);
    }

    public function testInvalidFromDateFailsWithMessage(): void
    {
        $tester = $this->commandTester(self::createStub(User::class), self::createStub(TicketSystem::class), $this->completedRun());

        $exitCode = $tester->execute(['username' => 'jdoe', 'ticket-system' => '1', '--from' => 'not-a-date']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid date', $tester->getDisplay());
    }
}

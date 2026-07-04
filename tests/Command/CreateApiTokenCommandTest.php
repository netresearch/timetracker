<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Command;

use App\Command\CreateApiTokenCommand;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Service\ApiToken\ApiTokenService;
use App\Service\FrozenClock;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(CreateApiTokenCommand::class)]
final class CreateApiTokenCommandTest extends TestCase
{
    private ?ApiToken $persisted = null;

    public function testCreatesTokenAndPrintsThePlaintextOnce(): void
    {
        $tester = new CommandTester($this->makeCommand(new User()->setUsername('jane')->setType('DEV')));
        $status = $tester->execute(['username' => 'jane', 'name' => 'ci', '--scope' => ['entries:write', 'entries:read']]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertInstanceOf(ApiToken::class, $this->persisted);
        self::assertSame(['entries:write', 'entries:read'], $this->persisted->getScopes());
        // The one-time plaintext is shown, and only the hash is stored.
        self::assertStringContainsString('tt_pat_', $tester->getDisplay());
        self::assertNotSame('', $this->persisted->getTokenHash());
    }

    public function testFailsForUnknownUser(): void
    {
        $tester = new CommandTester($this->makeCommand(null));
        $status = $tester->execute(['username' => 'ghost', 'name' => 'x', '--scope' => ['entries:read']]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No user', $tester->getDisplay());
    }

    public function testFailsWithoutScope(): void
    {
        $tester = new CommandTester($this->makeCommand(new User()->setUsername('jane')->setType('DEV')));
        $status = $tester->execute(['username' => 'jane', 'name' => 'x']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('scope', $tester->getDisplay());
    }

    public function testFailsForUnknownScope(): void
    {
        $tester = new CommandTester($this->makeCommand(new User()->setUsername('jane')->setType('DEV')));
        $status = $tester->execute(['username' => 'jane', 'name' => 'x', '--scope' => ['entries:delete']]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('scope', $tester->getDisplay());
    }

    private function makeCommand(?User $user): Command
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->userRepositoryReturning($user));
        $entityManager->method('persist')->willReturnCallback(function (object $object): void {
            if ($object instanceof ApiToken) {
                $this->persisted = $object;
            }
        });

        $clock = new FrozenClock('2024-01-15 12:00:00');
        $service = new ApiTokenService($entityManager, self::createStub(ApiTokenRepository::class), $clock);

        $command = new Command('app:api-token:create');
        $command->setCode(new CreateApiTokenCommand($entityManager, $service, $clock));

        return $command;
    }

    private function userRepositoryReturning(?User $user): EntityRepository
    {
        $repository = self::createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        return $repository;
    }
}

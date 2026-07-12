<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Command;

use App\Command\TtMatchPersonioEmployeesCommand;
use App\Entity\PersonioConfig;
use App\Entity\User;
use App\Repository\PersonioConfigRepository;
use App\Repository\UserRepository;
use App\Service\Personio\EmployeeMatcher;
use App\Service\Personio\PersonioClient;
use App\Service\Personio\PersonioClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 *
 * @coversNothing
 */
#[AllowMockObjectsWithoutExpectations]
final class TtMatchPersonioEmployeesCommandTest extends TestCase
{
    private PersonioConfigRepository&MockObject $configRepository;
    private UserRepository&MockObject $userRepository;
    private PersonioClient&MockObject $client;
    private EntityManagerInterface&MockObject $entityManager;

    /**
     * @param list<User>                                                                       $users
     * @param list<array{id: string, first_name: ?string, last_name: ?string, email: ?string}> $persons
     */
    private function tester(array $users, array $persons, bool $active = true): CommandTester
    {
        $this->configRepository = $this->createMock(PersonioConfigRepository::class);
        $this->configRepository->method('findActive')->willReturn($active ? new PersonioConfig() : null);

        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userRepository->method('findWithoutPersonioEmployeeId')->willReturn($users);

        $this->client = $this->createMock(PersonioClient::class);
        $this->client->method('listPersons')->willReturn($persons);
        $clientFactory = $this->createMock(PersonioClientFactory::class);
        $clientFactory->method('create')->willReturn($this->client);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        return new CommandTester(
            new TtMatchPersonioEmployeesCommand(
                $this->configRepository,
                $clientFactory,
                $this->userRepository,
                new EmployeeMatcher(),
                $this->entityManager,
            ),
        );
    }

    public function testPreviewListsProposalsWithoutWriting(): void
    {
        $user = $this->user(7, 'sebastian.mendel');
        $tester = $this->tester([$user], [$this->person('100', 'Sebastian', 'Mendel', 'sebastian.mendel@netresearch.de')]);

        $this->entityManager->expects(self::never())->method('flush');

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('sebastian.mendel', $tester->getDisplay());
        self::assertStringContainsString('100', $tester->getDisplay());
        self::assertNull($user->getPersonioEmployeeId());
    }

    public function testApplyWritesEmployeeId(): void
    {
        $user = $this->user(7, 'sebastian.mendel');
        $tester = $this->tester([$user], [$this->person('100', 'Sebastian', 'Mendel', 'sebastian.mendel@netresearch.de')]);

        $this->entityManager->expects(self::once())->method('flush');

        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(0, $exitCode);
        self::assertSame(100, $user->getPersonioEmployeeId());
        self::assertStringContainsString('Applied 1', $tester->getDisplay());
    }

    public function testNoActiveConfigFails(): void
    {
        $tester = $this->tester([], [], active: false);

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No active Personio configuration', $tester->getDisplay());
    }

    public function testAllMappedNote(): void
    {
        $tester = $this->tester([], []);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('already', $tester->getDisplay());
    }

    public function testNoConfidentMatchesNote(): void
    {
        $tester = $this->tester(
            [$this->user(7, 'sebastian.mendel')],
            [$this->person('100', 'Alice', 'Adams', 'alice.adams@netresearch.de')],
        );

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No confident matches', $tester->getDisplay());
    }

    private function user(int $id, string $username): User
    {
        $user = new User()->setUsername($username);
        $property = new ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }

    /**
     * @return array{id: string, first_name: ?string, last_name: ?string, email: ?string}
     */
    private function person(string $id, ?string $first, ?string $last, ?string $email): array
    {
        return ['id' => $id, 'first_name' => $first, 'last_name' => $last, 'email' => $email];
    }
}

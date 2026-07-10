<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Repository\UserTicketsystemRepository;
use Tests\AbstractWebTestCase;
use Tests\Traits\CreatesWorklogSyncFixtures;

use function array_map;
use function assert;

/**
 * @internal
 *
 * @coversNothing
 */
final class WorklogSyncOptInQueriesTest extends AbstractWebTestCase
{
    use CreatesWorklogSyncFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorklogSyncFixtures();
    }

    public function testFindSyncEnabledReturnsOnlyOptedInConnectedRows(): void
    {
        $enabled = $this->createUserTicketsystem($this->admin, syncEnabled: true);
        $syncAllOnly = $this->createUserTicketsystem($this->developer, syncAll: true);
        $plain = $this->createUserTicketsystem($this->userById(3));
        $enabledButAvoided = $this->createUserTicketsystem($this->userById(4), syncEnabled: true, avoidConnection: true);
        $enabledButTokenless = $this->createUserTicketsystem($this->userById(5), syncEnabled: true, accessToken: '');
        $this->entityManager->flush();

        $rows = $this->repository()->findSyncEnabled($this->ticketSystem);

        $ids = $this->idsOf($rows);
        self::assertContains($enabled->getId(), $ids);
        self::assertNotContains($syncAllOnly->getId(), $ids);
        self::assertNotContains($plain->getId(), $ids);
        self::assertNotContains($enabledButAvoided->getId(), $ids, 'avoidConnection rows are excluded.');
        self::assertNotContains($enabledButTokenless->getId(), $ids, 'Rows without a token are excluded.');
    }

    public function testFindSyncAllOwnersReturnsOnlySyncAllConnectedRows(): void
    {
        $syncAll = $this->createUserTicketsystem($this->admin, syncAll: true);
        $enabledOnly = $this->createUserTicketsystem($this->developer, syncEnabled: true);
        $plain = $this->createUserTicketsystem($this->userById(3));
        $syncAllButAvoided = $this->createUserTicketsystem($this->userById(4), syncAll: true, avoidConnection: true);
        $syncAllButTokenless = $this->createUserTicketsystem($this->userById(5), syncAll: true, accessToken: '');
        $this->entityManager->flush();

        $rows = $this->repository()->findSyncAllOwners($this->ticketSystem);

        $ids = $this->idsOf($rows);
        self::assertContains($syncAll->getId(), $ids);
        self::assertNotContains($enabledOnly->getId(), $ids);
        self::assertNotContains($plain->getId(), $ids);
        self::assertNotContains($syncAllButAvoided->getId(), $ids, 'avoidConnection rows are excluded.');
        self::assertNotContains($syncAllButTokenless->getId(), $ids, 'Rows without a token are excluded.');
    }

    public function testFindersScopeToTheGivenTicketSystem(): void
    {
        $otherTicketSystem = new TicketSystem();
        $otherTicketSystem->setName('otherOptIn');
        $otherTicketSystem->setUrl('https://other.example.com');
        $otherTicketSystem->setTicketUrl('https://other.example.com/browse/{ticket}');
        $otherTicketSystem->setLogin('other');
        $otherTicketSystem->setPassword('other');
        $this->entityManager->persist($otherTicketSystem);

        $onOther = $this->createUserTicketsystem($this->admin, syncEnabled: true, ticketSystem: $otherTicketSystem);
        $this->entityManager->flush();

        $ids = $this->idsOf($this->repository()->findSyncEnabled($this->ticketSystem));
        self::assertNotContains($onOther->getId(), $ids);
    }

    private function repository(): UserTicketsystemRepository
    {
        $repository = self::getContainer()->get(UserTicketsystemRepository::class);
        assert($repository instanceof UserTicketsystemRepository);

        return $repository;
    }

    private function userById(int $id): User
    {
        $user = $this->entityManager->find(User::class, $id);
        assert($user instanceof User);

        return $user;
    }

    private function createUserTicketsystem(
        User $user,
        bool $syncEnabled = false,
        bool $syncAll = false,
        bool $avoidConnection = false,
        string $accessToken = 'token',
        ?TicketSystem $ticketSystem = null,
    ): UserTicketsystem {
        $userTicketsystem = new UserTicketsystem();
        $userTicketsystem
            ->setUser($user)
            ->setTicketSystem($ticketSystem ?? $this->ticketSystem)
            ->setAccessToken($accessToken)
            ->setTokenSecret('secret')
            ->setAvoidConnection($avoidConnection)
            ->setSyncEnabled($syncEnabled)
            ->setSyncAll($syncAll);
        $this->entityManager->persist($userTicketsystem);

        return $userTicketsystem;
    }

    /**
     * @param list<UserTicketsystem> $rows
     *
     * @return list<int|null>
     */
    private function idsOf(array $rows): array
    {
        return array_map(static fn (UserTicketsystem $row): ?int => $row->getId(), $rows);
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Service\Sync\JiraAuthorMapper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(JiraAuthorMapper::class)]
#[AllowMockObjectsWithoutExpectations]
final class JiraAuthorMapperTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private EntityRepository&MockObject $userTicketsystemRepository;

    private EntityRepository&MockObject $userRepository;

    private JiraAuthorMapper $mapper;

    private TicketSystem $ticketSystem;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userTicketsystemRepository = $this->createMock(EntityRepository::class);
        $this->userRepository = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository&MockObject => UserTicketsystem::class === $class ? $this->userTicketsystemRepository : $this->userRepository,
        );
        $this->mapper = new JiraAuthorMapper($this->entityManager);
        $this->ticketSystem = self::createStub(TicketSystem::class);
    }

    public function testFindByStoredRemoteAccountId(): void
    {
        $user = new User();
        $mapping = new UserTicketsystem()->setUser($user);
        $this->userTicketsystemRepository->method('findOneBy')->willReturn($mapping);

        $found = $this->mapper->find(new JiraWorkLog(id: 1, authorAccountId: 'abc-123'), $this->ticketSystem);

        self::assertSame($user, $found);
    }

    public function testFindByUsernameMatchPersistsMapping(): void
    {
        $user = new User()->setUsername('jdoe');
        $this->userTicketsystemRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?User => ($criteria['username'] ?? null) === 'jdoe' ? $user : null,
        );
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $object) use (&$persisted): void { $persisted[] = $object; },
        );

        $found = $this->mapper->find(new JiraWorkLog(id: 1, authorAccountId: 'abc-123', authorName: 'jdoe'), $this->ticketSystem);

        self::assertSame($user, $found);
        self::assertCount(1, $persisted);
        self::assertInstanceOf(UserTicketsystem::class, $persisted[0]);
        self::assertSame('abc-123', $persisted[0]->getRemoteAccountId());
    }

    public function testFindByEmailLocalpart(): void
    {
        $user = new User()->setUsername('jdoe');
        $this->userTicketsystemRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?User => ($criteria['username'] ?? null) === 'jdoe' ? $user : null,
        );

        $found = $this->mapper->find(new JiraWorkLog(id: 1, authorEmail: 'jdoe@example.com'), $this->ticketSystem);

        self::assertSame($user, $found);
    }

    public function testFindReturnsNullForUnknownAuthor(): void
    {
        $this->userTicketsystemRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findOneBy')->willReturn(null);

        self::assertNull($this->mapper->find(new JiraWorkLog(id: 1, authorName: 'ghost'), $this->ticketSystem));
    }

    public function testCreateShadowPersistsInactiveUserWithMapping(): void
    {
        $this->userRepository->method('findOneBy')->willReturn(null);
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $object) use (&$persisted): void { $persisted[] = $object; },
        );

        $shadow = $this->mapper->createShadow(new JiraWorkLog(id: 1, authorAccountId: 'abc-123', authorName: 'ghost'), $this->ticketSystem);

        self::assertSame('ghost', $shadow->getUsername());
        self::assertFalse($shadow->getActive());
        self::assertContains($shadow, $persisted);
        $mappings = array_filter($persisted, static fn (object $object): bool => $object instanceof UserTicketsystem);
        self::assertCount(1, $mappings);
    }

    public function testShadowUsernameCollisionGetsSuffix(): void
    {
        $this->userRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?User => ($criteria['username'] ?? null) === 'ghost' ? new User() : null,
        );
        $this->entityManager->method('persist');

        $shadow = $this->mapper->createShadow(new JiraWorkLog(id: 1, authorName: 'ghost'), $this->ticketSystem);

        self::assertSame('ghost-2', $shadow->getUsername());
    }

    public function testRemoteKeyPrefersAccountId(): void
    {
        self::assertSame('abc', $this->mapper->remoteKey(new JiraWorkLog(id: 1, authorAccountId: 'abc', authorName: 'n')));
        self::assertSame('n', $this->mapper->remoteKey(new JiraWorkLog(id: 1, authorName: 'n')));
        self::assertNull($this->mapper->remoteKey(new JiraWorkLog(id: 1)));
    }
}

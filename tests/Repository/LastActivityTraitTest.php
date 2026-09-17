<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class LastActivityTraitTest extends TestCase
{
    public function testRejectsNonWhitelistedColumn(): void
    {
        $repository = new LastActivityDouble(self::createStub(EntityManagerInterface::class));

        $this->expectException(InvalidArgumentException::class);
        // Anything outside the FK whitelist (here the removed account_id) is refused
        // before it reaches the query — no interpolation of untrusted input.
        $repository->lastActivityBy('account_id');
    }

    public function testValidColumnAggregatesEntriesByMaxDay(): void
    {
        $connection = self::createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllKeyValue')
            ->with(self::stringContains('GROUP BY customer_id'))
            ->willReturn([1 => '2026-06-24', 2 => '2025-01-01']);

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        self::assertSame(
            [1 => '2026-06-24', 2 => '2025-01-01'],
            new LastActivityDouble($entityManager)->lastActivityBy('customer_id'),
        );
    }

    public function testUserIdNarrowsTheAggregateToThatUsersOwnEntries(): void
    {
        $connection = self::createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllKeyValue')
            ->with(self::stringContains('AND user_id = :userId'), ['userId' => 7])
            ->willReturn([849 => '2026-09-16']);

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        self::assertSame(
            [849 => '2026-09-16'],
            new LastActivityDouble($entityManager)->lastActivityBy('project_id', 7),
        );
    }

    public function testWithoutUserIdTheQueryCarriesNoUserFilterAndNoParameters(): void
    {
        $connection = self::createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllKeyValue')
            ->with(self::logicalNot(self::stringContains('user_id = :userId')), [])
            ->willReturn([]);

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        self::assertSame([], new LastActivityDouble($entityManager)->lastActivityBy('project_id'));
    }
}

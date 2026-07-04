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
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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

    public function testCachesTheAggregateSoASecondCallDoesNotRequeryTheEntriesTable(): void
    {
        $connection = self::createMock(Connection::class);
        // The full-table GROUP BY MAX(day) must run ONCE across two calls.
        $connection->expects(self::once())
            ->method('fetchAllKeyValue')
            ->willReturn([1 => '2026-07-01']);

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $repository = new LastActivityDouble($entityManager, new ArrayAdapter());

        self::assertSame([1 => '2026-07-01'], $repository->lastActivityBy('project_id'));
        self::assertSame([1 => '2026-07-01'], $repository->lastActivityBy('project_id'));
    }

    public function testDifferentColumnsDoNotShareACacheEntry(): void
    {
        $connection = self::createMock(Connection::class);
        // Distinct columns → distinct cache keys → one query each, no collision.
        $connection->expects(self::exactly(2))
            ->method('fetchAllKeyValue')
            ->willReturn([1 => '2026-07-01']);

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $repository = new LastActivityDouble($entityManager, new ArrayAdapter());
        $repository->lastActivityBy('project_id');
        $repository->lastActivityBy('customer_id');
    }
}

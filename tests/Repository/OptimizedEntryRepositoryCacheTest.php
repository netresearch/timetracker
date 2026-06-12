<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use App\Entity\Entry;
use App\Enum\Period;
use App\Repository\OptimizedEntryRepository;
use App\Service\ClockInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Unit test for the cache-hit fast path of OptimizedEntryRepository.
 *
 * @internal
 */
#[CoversClass(OptimizedEntryRepository::class)]
final class OptimizedEntryRepositoryCacheTest extends TestCase
{
    public function testGetWorkByUserOptimizedReturnsCachedShape(): void
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn(new ClassMetadata(Entry::class));

        $managerRegistry = self::createStub(ManagerRegistry::class);
        $managerRegistry->method('getManagerForClass')->willReturn($entityManager);

        $cacheItem = self::createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(['duration' => 120, 'count' => 3, 'stale_extra' => 'x']);

        $cacheItemPool = self::createStub(CacheItemPoolInterface::class);
        $cacheItemPool->method('getItem')->willReturn($cacheItem);

        $optimizedEntryRepository = new OptimizedEntryRepository(
            $managerRegistry,
            self::createStub(ClockInterface::class),
            $cacheItemPool,
        );

        // The cached payload must be narrowed to exactly the documented shape.
        self::assertSame(
            ['duration' => 120, 'count' => 3],
            $optimizedEntryRepository->getWorkByUserOptimized(7, Period::DAY),
        );
    }
}

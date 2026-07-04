<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards the DI wiring, not the logic: the $cacheItemPool bind must actually
 * inject a pool into the list repositories. A silent null (mis-bound arg) would
 * make LastActivityTrait's cache a no-op and the aggregate would run on every
 * request again — invisibly.
 *
 * @internal
 */
final class LastActivityCacheWiringTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        // Symfony's kernel registers an exception handler during boot; restore it
        // so PHPUnit doesn't flag the test as risky.
        restore_exception_handler();

        parent::tearDown();
    }

    /**
     * @param class-string $repositoryClass
     */
    #[DataProvider('listRepositories')]
    public function testTheListRepositoryReceivesACachePool(string $repositoryClass): void
    {
        self::bootKernel();

        $repository = self::getContainer()->get($repositoryClass);

        $cache = new ReflectionProperty($repositoryClass, 'lastActivityCache')->getValue($repository);
        self::assertInstanceOf(
            CacheItemPoolInterface::class,
            $cache,
            $repositoryClass . ' must receive a cache pool (the $cacheItemPool bind), not null.',
        );
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function listRepositories(): iterable
    {
        yield 'customers' => [CustomerRepository::class];
        yield 'projects' => [ProjectRepository::class];
        yield 'users' => [UserRepository::class];
    }
}

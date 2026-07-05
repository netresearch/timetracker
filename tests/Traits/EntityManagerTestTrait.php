<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Traits;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared Doctrine helpers for functional tests: a typed EntityManager accessor
 * (via the public `doctrine` service, so it stays env-independent) and a
 * user-by-username lookup. Requires the using class to be a KernelTestCase (for
 * self::getContainer()).
 */
trait EntityManagerTestTrait
{
    protected function entityManager(): EntityManagerInterface
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(Registry::class, $doctrine);
        $manager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    protected function user(string $username): User
    {
        $user = $this->entityManager()->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}

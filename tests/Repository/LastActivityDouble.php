<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\LastActivityTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Concrete user of LastActivityTrait so its public method can be unit-tested (and
 * statically typed) without standing up a full Doctrine repository.
 */
final class LastActivityDouble
{
    use LastActivityTrait;

    public function __construct(private readonly EntityManagerInterface $entityManager, ?CacheItemPoolInterface $cacheItemPool = null)
    {
        $this->lastActivityCache = $cacheItemPool;
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}

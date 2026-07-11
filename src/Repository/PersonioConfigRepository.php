<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PersonioConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonioConfig>
 */
class PersonioConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonioConfig::class);
    }

    public function findActive(): ?PersonioConfig
    {
        return $this->findOneBy(['active' => true]);
    }

    public function findOneByName(string $name): ?PersonioConfig
    {
        return $this->findOneBy(['name' => $name]);
    }
}

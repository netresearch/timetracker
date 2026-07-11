<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PersonioAttendanceExport;
use App\Entity\User;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonioAttendanceExport>
 */
class PersonioAttendanceExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonioAttendanceExport::class);
    }

    public function findOneByUserAndDay(User $user, DateTimeInterface $day): ?PersonioAttendanceExport
    {
        return $this->findOneBy(['user' => $user, 'day' => $day->format('Y-m-d')]);
    }
}

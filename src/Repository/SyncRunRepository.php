<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncRun>
 */
class SyncRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncRun::class);
    }

    /**
     * Latest runs, newest first (ADR-023 §6 run listing).
     *
     * @return list<SyncRun>
     */
    public function findLatest(int $limit = 20, ?TicketSystem $ticketSystem = null): array
    {
        $queryBuilder = $this->createQueryBuilder('r')
            ->orderBy('r.startedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit);

        if ($ticketSystem instanceof TicketSystem) {
            $queryBuilder
                ->andWhere('r.ticketSystem = :ticketSystem')
                ->setParameter('ticketSystem', $ticketSystem);
        }

        /** @var list<SyncRun> */
        return $queryBuilder->getQuery()->getResult();
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
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
     * Latest runs, newest first (ADR-023 §6 run listing). The optional
     * $triggeredBy filter is applied in the query so the limit bounds the
     * caller's OWN runs — filtering after the limit would let a non-admin
     * receive fewer (or zero) runs when other users' runs fill the window.
     *
     * @return list<SyncRun>
     */
    public function findLatest(int $limit = 20, ?TicketSystem $ticketSystem = null, ?User $triggeredBy = null): array
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

        if ($triggeredBy instanceof User) {
            $queryBuilder
                ->andWhere('r.triggeredBy = :triggeredBy')
                ->setParameter('triggeredBy', $triggeredBy);
        }

        /** @var list<SyncRun> */
        return $queryBuilder->getQuery()->getResult();
    }
}

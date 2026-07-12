<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SyncRun;
use App\Entity\SyncRunItem;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncItemKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function array_keys;
use function is_string;
use function sort;
use function strstr;

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

    /**
     * Distinct Jira key PREFIXES (the part before the first '-') of the
     * UNRESOLVED_PROJECT items parked by the most recent $runLimit sync runs of
     * a ticket system (ADR-026 P1). These are the prefixes with no owning TT
     * project — the input to the project-import review screen. Sorted for a
     * stable order; empty when nothing is parked.
     *
     * @return list<string>
     */
    public function findUnresolvedProjectPrefixes(TicketSystem $ticketSystem, int $runLimit = 20): array
    {
        /** @var list<int> $runIds */
        $runIds = $this->createQueryBuilder('r')
            ->select('r.id')
            ->where('r.ticketSystem = :ticketSystem')
            ->setParameter('ticketSystem', $ticketSystem)
            ->orderBy('r.startedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($runLimit)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $runIds) {
            return [];
        }

        /** @var list<mixed> $issueKeys */
        $issueKeys = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT i.issueKey')
            ->from(SyncRunItem::class, 'i')
            ->where('i.syncRun IN (:runIds)')
            ->setParameter('runIds', $runIds)
            ->andWhere('i.kind = :kind')
            ->setParameter('kind', SyncItemKind::UNRESOLVED_PROJECT)
            ->getQuery()
            ->getSingleColumnResult();

        $prefixes = [];
        foreach ($issueKeys as $issueKey) {
            if (!is_string($issueKey)) {
                continue;
            }

            if ('' === $issueKey) {
                continue;
            }

            // "SRVMO-123" -> "SRVMO"; a key with no '-' is its own prefix.
            $prefix = strstr($issueKey, '-', true);
            $prefix = false === $prefix ? $issueKey : $prefix;
            if ('' !== $prefix) {
                $prefixes[$prefix] = true;
            }
        }

        $prefixes = array_keys($prefixes);
        sort($prefixes);

        return $prefixes;
    }
}

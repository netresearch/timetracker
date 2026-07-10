<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\WorklogSyncStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorklogSyncState>
 */
class WorklogSyncStateRepository extends ServiceEntityRepository
{
    /**
     * Statuses of parked (attention-needing) states — conflict or orphaned (ADR-023 §2).
     */
    private const array PARKED_STATUSES = [WorklogSyncStatus::CONFLICT, WorklogSyncStatus::ORPHANED];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorklogSyncState::class);
    }

    /**
     * Parked states (conflict/orphaned) with their entries fetched, most recently synced first.
     *
     * @param User|null $user optional owner filter (entry's user)
     *
     * @return list<WorklogSyncState>
     */
    public function findParked(?User $user = null, int $limit = 100): array
    {
        $queryBuilder = $this->createQueryBuilder('s')
            ->join('s.entry', 'e')->addSelect('e')
            ->join('e.user', 'u')->addSelect('u')
            ->where('s.status IN (:parked)')
            ->setParameter('parked', self::PARKED_STATUSES)
            ->orderBy('s.lastSyncedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults($limit);

        if ($user instanceof User) {
            $queryBuilder
                ->andWhere('e.user = :user')
                ->setParameter('user', $user);
        }

        /** @var list<WorklogSyncState> */
        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Parked state by id — returns null for rows that are IN_SYNC (not parked).
     */
    public function findParkedById(int $id): ?WorklogSyncState
    {
        /** @var WorklogSyncState|null */
        return $this->createQueryBuilder('s')
            ->join('s.entry', 'e')->addSelect('e')
            ->join('e.user', 'u')->addSelect('u')
            ->where('s.id = :id')
            ->andWhere('s.status IN (:parked)')
            ->setParameter('id', $id)
            ->setParameter('parked', self::PARKED_STATUSES)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, WorklogSyncState> keyed by entry id
     */
    public function findByEntryIds(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }

        /** @var list<WorklogSyncState> $states */
        $states = $this->createQueryBuilder('s')
            ->where('s.entry IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getResult();

        $byEntryId = [];
        foreach ($states as $state) {
            $entry = $state->getEntry();
            if (null !== $entry && null !== $entry->getId()) {
                $byEntryId[$entry->getId()] = $state;
            }
        }

        return $byEntryId;
    }
}

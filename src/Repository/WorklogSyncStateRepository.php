<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorklogSyncState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorklogSyncState>
 */
class WorklogSyncStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorklogSyncState::class);
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

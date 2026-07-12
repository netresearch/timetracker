<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PersonioAbsenceImport;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonioAbsenceImport>
 */
class PersonioAbsenceImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonioAbsenceImport::class);
    }

    public function findOneByAbsenceId(string $absenceId): ?PersonioAbsenceImport
    {
        return $this->findOneBy(['absenceId' => $absenceId]);
    }

    /**
     * The import records for a user, keyed by Personio absence id — the set the
     * import diffs against Personio to spot cancellations (records with no
     * matching remote absence in the window).
     *
     * @return array<string, PersonioAbsenceImport>
     */
    public function findByUserIndexedByAbsenceId(User $user): array
    {
        $indexed = [];
        foreach ($this->findBy(['user' => $user]) as $record) {
            $absenceId = $record->getAbsenceId();
            if (null !== $absenceId) {
                $indexed[$absenceId] = $record;
            }
        }

        return $indexed;
    }
}

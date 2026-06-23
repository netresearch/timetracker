<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\User;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Database with all contracts.
 *
 * @author Tony Kreissl <kreissl@mogic.com>
 *
 * @extends ServiceEntityRepository<Contract>
 */
class ContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Contract::class);
    }

    /**
     * Find all contracts, sorted by start ascending.
     *
     * @return array<int, array{contract: array{id:int, user_id:int, start:string|null, end:string|null, hours_0:float, hours_1:float, hours_2:float, hours_3:float, hours_4:float, hours_5:float, hours_6:float}}>
     */
    public function getContracts(): array
    {
        $queryBuilder = $this->createQueryBuilder('contracts')
            ->join('contracts.user', 'users')
            ->orderBy('users.username', 'ASC')
            ->addOrderBy('contracts.start', 'ASC');

        $query = $queryBuilder->getQuery();
        /** @var Contract[] $contracts */
        $contracts = $query->getResult();
        $data = [];

        /** @var Contract $contract */
        foreach ($contracts as $contract) {
            $data[] = ['contract' => [
                'id' => (int) $contract->getId(),
                'user_id' => (int) $contract->getUser()->getId(),
                'start' => null !== $contract->getStart()
                    ? $contract->getStart()->format('Y-m-d')
                    : null,
                'end' => null !== $contract->getEnd()
                    ? $contract->getEnd()->format('Y-m-d')
                    : null,
                'hours_0' => (float) $contract->getHours0(),
                'hours_1' => (float) $contract->getHours1(),
                'hours_2' => (float) $contract->getHours2(),
                'hours_3' => (float) $contract->getHours3(),
                'hours_4' => (float) $contract->getHours4(),
                'hours_5' => (float) $contract->getHours5(),
                'hours_6' => (float) $contract->getHours6(),
            ]];
        }

        return $data;
    }

    /**
     * Find the user's contract that is valid on the given date.
     *
     * A contract is valid on a date when it has started on or before that date
     * and either has no end date or ends on or after it
     * (start <= date AND (end IS NULL OR end >= date)). When several contracts
     * overlap the date (which the admin save flow tries to prevent), the
     * latest-starting one wins.
     */
    public function findValidContract(User $user, DateTimeInterface $date): ?Contract
    {
        /** @var Contract|null $contract */
        $contract = $this->createQueryBuilder('contract')
            ->andWhere('contract.user = :user')
            ->andWhere('contract.start <= :date')
            ->andWhere('contract.end IS NULL OR contract.end >= :date')
            ->setParameter('user', $user)
            ->setParameter('date', $date)
            ->orderBy('contract.start', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $contract;
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TicketSystem;
use App\Entity\UserTicketsystem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserTicketsystem>
 */
class UserTicketsystemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserTicketsystem::class);
    }

    /**
     * Rows whose author opted their own worklogs into cron sync (ADR-023 amendment):
     * `sync_enabled`, connected (`avoidConnection = false`), with a non-empty token.
     *
     * @return list<UserTicketsystem>
     */
    public function findSyncEnabled(TicketSystem $ticketSystem): array
    {
        return $this->findConnectedOptIn($ticketSystem, 'uts.syncEnabled = true');
    }

    /**
     * PO rows that opted into sync-all (ADR-023 amendment): `sync_all`, connected
     * (`avoidConnection = false`), with a non-empty token. The runtime additionally
     * requires the user to be ROLE_PL/ADMIN — that check is not enforced here.
     *
     * @return list<UserTicketsystem>
     */
    public function findSyncAllOwners(TicketSystem $ticketSystem): array
    {
        return $this->findConnectedOptIn($ticketSystem, 'uts.syncAll = true');
    }

    /**
     * @param string $optInPredicate DQL predicate selecting the opt-in flag
     *
     * @return list<UserTicketsystem>
     */
    private function findConnectedOptIn(TicketSystem $ticketSystem, string $optInPredicate): array
    {
        /** @var list<UserTicketsystem> */
        return $this->createQueryBuilder('uts')
            ->join('uts.user', 'u')->addSelect('u')
            ->where('uts.ticketSystem = :ts')
            ->andWhere($optInPredicate)
            ->andWhere('uts.avoidConnection = false')
            ->andWhere("uts.accessToken != ''")
            ->setParameter('ts', $ticketSystem)
            ->getQuery()
            ->getResult();
    }
}

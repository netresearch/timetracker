<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    use LastActivityTrait;

    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Customer::class);
    }

    /**
     * Priority 2: Add explicit type-safe repository method for mixed type handling.
     */
    public function findOneById(int $id): ?Customer
    {
        $result = $this->find($id);

        return $result instanceof Customer ? $result : null;
    }

    /**
     * Returns an array of customers available for current user.
     *
     * @return array<int, array{customer: array{id:int, name:string, active:bool}}>
     */
    public function getCustomersByUser(int $userId): array
    {
        /** @var Customer[] $result */
        $result = $this->createQueryBuilder('customer')
            ->andWhere('customer.global = 1')
            ->orWhere('user.id = :userId')
            ->setParameter('userId', $userId)
            ->leftJoin('customer.teams', 'team')
            ->leftJoin('team.users', 'user')
            ->orderBy('customer.name', 'ASC')
            ->getQuery()
            ->execute();

        $data = [];
        foreach ($result as $customer) {
            $data[] = ['customer' => [
                'id' => (int) $customer->getId(),
                'name' => (string) $customer->getName(),
                'active' => (bool) $customer->getActive(),
            ]];
        }

        return $data;
    }

    /**
     * Returns an array of all available customers.
     *
     * @return array<int, array{customer: array{id:int, name:string, active:bool, global:bool, teams: array<int, int>, last_activity: string|null}}>
     */
    /**
     * @return array<int, array{customer: array{id:int, name:string, active:bool, global:bool, teams: array<int, int>, last_activity: string|null}}>
     */
    public function getAllCustomers(): array
    {
        // Fetch-join the teams relation: iterating getTeams() below would otherwise
        // lazy-load one collection per customer (N+1 — the dominant cost of /getAllCustomers).
        /** @var Customer[] $customers */
        $customers = $this->createQueryBuilder('customer')
            ->leftJoin('customer.teams', 'team')
            ->addSelect('team')
            ->orderBy('customer.name', 'ASC')
            ->getQuery()
            ->getResult();

        $lastActivity = $this->lastActivityBy('customer_id');

        $data = [];
        foreach ($customers as $customer) {
            $teams = [];
            foreach ($customer->getTeams() as $team) {
                $teams[] = (int) $team->getId();
            }

            $data[] = ['customer' => [
                'id' => (int) $customer->getId(),
                'name' => (string) $customer->getName(),
                'active' => (bool) $customer->getActive(),
                'global' => (bool) $customer->getGlobal(),
                'teams' => $teams,
                'last_activity' => $lastActivity[(int) $customer->getId()] ?? null,
            ]];
        }

        return $data;
    }

    public function findOneByName(string $name): ?Customer
    {
        $result = $this->findOneBy(['name' => $name]);

        return $result instanceof Customer ? $result : null;
    }

    /**
     * The customer carrying this stable Tempo customer key (ADR-026 P2), if any —
     * the idempotency key for the Tempo->Customer upsert: a re-import resolves the
     * existing Customer by key instead of duplicating it on name drift.
     */
    public function findOneByTempoCustomerKey(string $tempoCustomerKey): ?Customer
    {
        $result = $this->findOneBy(['tempoCustomerKey' => $tempoCustomerKey]);

        return $result instanceof Customer ? $result : null;
    }
}

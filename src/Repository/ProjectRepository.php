<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;

use function assert;
use function count;
use function is_array;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    use LastActivityTrait;

    public function __construct(ManagerRegistry $managerRegistry, ?CacheItemPoolInterface $cacheItemPool = null)
    {
        parent::__construct($managerRegistry, Project::class);
        $this->lastActivityCache = $cacheItemPool;
    }

    /**
     * Priority 2: Add explicit type-safe repository method for mixed type handling.
     */
    public function findOneById(int $id): ?Project
    {
        $result = $this->find($id);

        return $result instanceof Project ? $result : null;
    }

    /**
     * @return array<int, array{project: array<string, mixed>}>
     */
    public function getProjectsByUser(int $userId, int $customerId = 0): array
    {
        $queryBuilder = $this->createQueryBuilder('project')
            ->where('customer.global = 1 OR user.id = :userId')
            ->setParameter('userId', $userId);

        if ($customerId > 0) {
            $queryBuilder->andWhere('project.global = 1 OR customer.id = :customerId')
                ->setParameter('customerId', $customerId);
        }

        /** @var Project[] $result */
        $result = $queryBuilder->leftJoin('project.customer', 'customer')
            ->leftJoin('customer.teams', 'team')
            ->leftJoin('team.users', 'user')
            ->getQuery()
            ->execute();

        $data = [];
        foreach ($result as $project) {
            if ($project instanceof Project) {
                $data[] = ['project' => $project->toArray()];
            }
        }

        return $data;
    }

    /**
     * @return list<Project>
     */
    public function findByCustomer(int $customerId = 0): array
    {
        $result = $this->createQueryBuilder('project')
            ->where('project.global = 1 OR customer.id = :customerId')
            ->setParameter('customerId', $customerId)
            ->leftJoin('project.customer', 'customer')
            ->leftJoin('customer.teams', 'team')
            ->leftJoin('team.users', 'user')
            ->getQuery()
            ->getResult();

        assert(is_array($result));
        // All results are Project entities due to the repository context
        assert(array_is_list($result) || 0 === count($result));
        /** @var list<Project> $result */

        return $result;
    }

    /**
     * Active (or global) projects only — the bookable set the tracking-page picker
     * needs, without the large inactive tail (~94 active vs ~1000 total on prod).
     * Historical entries on a since-deactivated project still render because their
     * label is embedded in the entry (Entry::toArray), so display never depends on
     * the inactive projects being in this list. addSelect hydrates the customer so
     * Project::toArray doesn't lazy-load one proxy per project (N+1).
     *
     * @return list<Project>
     */
    public function findActiveOrGlobal(): array
    {
        $result = $this->createQueryBuilder('project')
            ->leftJoin('project.customer', 'customer')
            ->addSelect('customer')
            ->where('project.active = 1')
            ->orWhere('project.global = 1')
            ->orderBy('project.name', 'ASC')
            ->getQuery()
            ->getResult();

        assert(is_array($result) && (array_is_list($result) || 0 === count($result)));
        /** @var list<Project> $result */

        return $result;
    }

    /**
     * @return array<int, array{id: int, name: string, customerId: int, customerName: string}>
     */
    public function getAllProjectsForAdmin(): array
    {
        // addSelect hydrates the joined customer: getName() below would otherwise
        // initialize one customer proxy per project (N+1 lazy loads).
        $queryBuilder = $this->createQueryBuilder('p')
            ->leftJoin('p.customer', 'c')
            ->addSelect('c')
            ->orderBy('p.name', 'ASC');

        /** @var Project[] $projects */
        $projects = $queryBuilder->getQuery()->execute();

        $data = [];
        foreach ($projects as $project) {
            $customer = $project->getCustomer();
            $data[] = [
                'id' => (int) ($project->getId() ?? 0),
                'name' => (string) ($project->getName() ?? ''),
                'customerId' => null !== $customer ? (int) $customer->getId() : 0,
                'customerName' => null !== $customer ? (string) $customer->getName() : '',
            ];
        }

        return $data;
    }

    public function isValidJiraPrefix(string $jiraId): int
    {
        return (int) preg_match('/^([A-Z]+[A-Z0-9]*[, ]*)*$/', $jiraId);
    }
}

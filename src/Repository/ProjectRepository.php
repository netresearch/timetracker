<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Service\TypeSafety\ArrayTypeHelper;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function assert;
use function is_array;

/**
 * @extends ServiceEntityRepository<\App\Entity\Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Project::class);
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
     * Returns an array structure with keys of customer IDs and an "all" key.
     * Values are arrays of associative project arrays (id, name, jiraId, active).
     *
     * @param array<int, array{customer: array{id:int}}|array<string, mixed>> $customers
     *
     * @return array<int|string, list<array<string, mixed>>>
     */
    public function getProjectStructure(int $userId, array $customers): array
    {
        /** @var array<int, Project> $globalProjects */
        $globalProjects = $this->findBy(['global' => 1]);
        $userProjects = $this->getProjectsByUser($userId);

        $projects = [];
        foreach ($customers as $customer) {
            $customerData = $customer['customer'] ?? null;
            if (!is_array($customerData)) {
                continue;
            }

            /** @var array<string, mixed> $customerData */
            $customerId = ArrayTypeHelper::getInt($customerData, 'id');
            if (null === $customerId) {
                continue;
            }

            foreach ($userProjects as $userProject) {
                $up = $userProject['project'] ?? null;
                if (is_array($up) && ($customerId === ArrayTypeHelper::getInt($up, 'customer'))) {
                    $projects[$customerId][] = [
                        'id' => ArrayTypeHelper::getInt($up, 'id', 0) ?? 0,
                        'name' => ArrayTypeHelper::getString($up, 'name', '') ?? '',
                        'jiraId' => ArrayTypeHelper::getString($up, 'jiraId'),
                        'active' => (bool) ($up['active'] ?? false),
                    ];
                }
            }

            foreach ($globalProjects as $globalProject) {
                if ($globalProject instanceof Project) {
                    $projects[$customerId][] = [
                        'id' => $globalProject->getId(),
                        'name' => $globalProject->getName(),
                        'jiraId' => $globalProject->getJiraId(),
                        'active' => $globalProject->getActive(),
                    ];
                }
            }
        }

        foreach ($userProjects as $userProject) {
            $up = $userProject['project'] ?? null;
            if (is_array($up)) {
                $projects['all'][] = [
                    'id' => ArrayTypeHelper::getInt($up, 'id', 0),
                    'name' => ArrayTypeHelper::getString($up, 'name', ''),
                    'active' => (bool) ($up['active'] ?? false),
                    'customer' => ArrayTypeHelper::getInt($up, 'customer', 0),
                    'global' => (bool) ($up['global'] ?? false),
                    'jiraId' => ArrayTypeHelper::getString($up, 'jiraId'),
                    'jira_id' => ArrayTypeHelper::getString($up, 'jiraId'),
                    'subtickets' => [],
                    'entries' => [],
                    'projectLead' => $up['projectLead'] ?? null,
                    'project_lead' => $up['projectLead'] ?? null,
                    'technicalLead' => $up['technicalLead'] ?? null,
                    'technical_lead' => $up['technicalLead'] ?? null,
                ];
            }
        }

        foreach ($globalProjects as $globalProject) {
            if ($globalProject instanceof Project) {
                $projects['all'][] = [
                    'id' => (int) $globalProject->getId(),
                    'name' => $globalProject->getName(),
                    'jiraId' => $globalProject->getJiraId(),
                ];
            }
        }

        foreach ($projects as &$project) {
            // Both branches construct arrays; phpstan knows they are arrays
            usort($project, static fn (array $a, array $b): int => strcmp($a['name'] ?? '', $b['name'] ?? ''));
        }

        return $projects;
    }

    /**
     * @return array<int, array{project: array<string, mixed>}>
     */
    public function getProjectsByUser(int $userId, int $customerId = 0): array
    {
        $queryBuilder = $this->createQueryBuilder('project')
            ->where('customer.global = 1 OR user.id = :userId')
            ->setParameter('userId', $userId)
        ;

        if ($customerId > 0) {
            $queryBuilder->andWhere('project.global = 1 OR customer.id = :customerId')
                ->setParameter('customerId', $customerId)
            ;
        }

        /** @var Project[] $result */
        $result = $queryBuilder->leftJoin('project.customer', 'customer')
            ->leftJoin('customer.teams', 'team')
            ->leftJoin('team.users', 'user')
            ->getQuery()
            ->execute()
        ;

        $data = [];
        foreach ($result as $project) {
            if ($project instanceof Project) {
                $data[] = ['project' => $project->toArray()];
            }
        }

        return $data;
    }

    /**
     * @return array<int, Project>
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
            ->getResult()
        ;

        assert(is_array($result));
        // All results are Project entities due to the repository context
        assert(array_is_list($result) || $result === []);

        return $result;
    }

    /**
     * @return array<int, array{id: int, name: string, customerId: int, customerName: string}>
     */
    public function getAllProjectsForAdmin(): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->leftJoin('p.customer', 'c')
            ->orderBy('p.name', 'ASC')
        ;

        /** @var Project[] $projects */
        $projects = $queryBuilder->getQuery()->execute();

        $data = [];
        foreach ($projects as $project) {
            $customer = $project->getCustomer();
            $data[] = [
                'id' => (int) ($project->getId() ?? 0),
                'name' => (string) ($project->getName() ?? ''),
                'customerId' => $customer ? (int) $customer->getId() : 0,
                'customerName' => $customer ? (string) $customer->getName() : '',
            ];
        }

        return $data;
    }

    public function isValidJiraPrefix(string $jiraId): int
    {
        return (int) preg_match('/^([A-Z]+[A-Z0-9]*[, ]*)*$/', $jiraId);
    }
}

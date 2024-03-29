<?php declare(strict_types=1);

namespace App\Repository;

use ReflectionException;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class ProjectRepository.
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * @return Project[]
     */
    public function getGlobalProjects(): array
    {
        return $this->findBy(['global' => 1]);
    }

    /**
     * Returns an array structure with keys of customer IDs
     * The values are arrays of projects.
     *
     * There is a special key "all", where all projects are in.
     *
     * @throws ReflectionException
     *
     * @return array[][]
     */
    public function getProjectStructure(int $userId, array $customers): array
    {
        /** @var Project[] $globalProjects */
        $globalProjects = $this->getGlobalProjects();
        $userProjects   = $this->getProjectsByUser($userId);

        $projects = [];
        foreach ($customers as $customer) {
            // Restructure customer-specific projects
            foreach ($userProjects as $project) {
                if ($customer['customer']['id'] === $project['project']['customer']) {
                    $projects[$customer['customer']['id']][] = [
                        'id'     => $project['project']['id'],
                        'name'   => $project['project']['name'],
                        'jiraId' => $project['project']['jiraId'],
                        'active' => $project['project']['active'],
                    ];
                }
            }

            // Add global projects to each customer
            foreach ($globalProjects as $global) {
                $projects[$customer['customer']['id']][] = [
                    'id'     => $global->getId(),
                    'name'   => $global->getName(),
                    'jiraId' => $global->getJiraId(),
                    'active' => $global->getActive(),
                ];
            }
        }

        // Add each customer-specific project to the all-projects-list
        foreach ($userProjects as $project) {
            $projects['all'][] = $project['project'];
        }

        // Add each global project to the all-projects-list
        foreach ($globalProjects as $global) {
            $projects['all'][] = [
                'id'     => $global->getId(),
                'name'   => $global->getName(),
                'jiraId' => $global->getJiraId(),
            ];
        }

        // Sort projects by name for each customer
        foreach ($projects as &$customerProjects) {
            usort(
                $customerProjects,
                function (array $projectA, array $projectB): int
                {
                    return strcasecmp($projectA['name'], $projectB['name']);
                }
            );
        }

        return $projects;
    }

    /**
     * Returns projects for given user, and optionally for given customer.
     *
     * @throws ReflectionException
     *
     * @return array[]
     */
    public function getProjectsByUser(int $userId, int $customerId = 0): array
    {
        $qb = $this->createQueryBuilder('project')
            ->where('customer.global = :global OR user.id = :userId')
            ->setParameter('global', true)
            ->setParameter('userId', $userId)
        ;

        if ($customerId > 0) {
            $qb->andWhere('project.global = :global OR customer.id = :customerId')
                ->setParameter('global', true)
                ->setParameter('customerId', $customerId)
            ;
        }

        /** @var Project[] $result */
        $result = $qb->leftJoin('project.customer', 'customer')
            ->leftJoin('customer.teams', 'team')
            ->leftJoin('team.users', 'user')
            ->getQuery()
            ->execute()
        ;

        $data = [];
        foreach ($result as $project) {
            $data[] = ['project' => $project->toArray()];
        }

        return $data;
    }

    /**
     * @return Project[]
     */
    public function findByCustomer(int $customerId = 0): array
    {
        /* @var Project[] $result */
        return $this->createQueryBuilder('project')
            ->where('project.global = :global OR customer.id = :customerId')
            ->setParameter('global', true)
            ->setParameter('customerId', $customerId)
            ->leftJoin('project.customer', 'customer')
            ->leftJoin('customer.teams', 'team')
            ->leftJoin('team.users', 'user')
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * @param $jiraId
     */
    public function isValidJiraPrefix($jiraId): int|false
    {
        return preg_match('/^([A-Z]+[A-Z0-9]*[, ]*)*$/', $jiraId);
    }
}

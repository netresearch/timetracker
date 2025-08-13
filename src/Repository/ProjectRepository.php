<?php

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class ProjectRepository
 * @package App\Repository
 */
class ProjectRepository extends ServiceEntityRepository
{
    /**
     * ProjectRepository constructor.
     */
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Project::class);
    }

    /**
     * @param string $a
     * @param string $b
     */
    public function sortProjectsByName($a, $b): int
    {
        $aName = is_array($a) ? ($a['name'] ?? '') : (is_object($a) && property_exists($a, 'name') ? (string) $a->name : '');
        $bName = is_array($b) ? ($b['name'] ?? '') : (is_object($b) && property_exists($b, 'name') ? (string) $b->name : '');
        return strcasecmp((string) $aName, (string) $bName);
    }

    /**
     * @return Project[]
     */
    public function getGlobalProjects()
    {
        return $this->findBy(['global' => 1]);
    }


    /**
     * Returns an array structure with keys of customer IDs
     * The values are arrays of projects.
     *
     * There is a special key "all", where all projects are in.
     * @return array[][]
     * @throws \ReflectionException
     */
    public function getProjectStructure(int $userId, array $customers): array
    {
        /** @var \App\Entity\Project[] $globalProjects */
        $globalProjects = $this->getGlobalProjects();
        /** @var \App\Entity\Project[] $userProjects */
        $userProjects   = $this->getProjectsByUser($userId);

        $projects = [];
        foreach ($customers as $customer) {

            // Restructure customer-specific projects
            foreach ($userProjects as $userProject) {
                if ($customer['customer']['id'] === $userProject['project']['customer']) {
                    $projects[$customer['customer']['id']][] = [
                        'id'     => $userProject['project']['id'],
                        'name'   => $userProject['project']['name'],
                        'jiraId' => $userProject['project']['jiraId'],
                        'active' => $userProject['project']['active'],
                    ];
                }
            }

            // Add global projects to each customer
            foreach ($globalProjects as $globalProject) {
                $projects[$customer['customer']['id']][] = [
                    'id'     => $globalProject->getId(),
                    'name'   => $globalProject->getName(),
                    'jiraId' => $globalProject->getJiraId(),
                    'active' => $globalProject->getActive(),
                ];
            }
        }

        // Add each customer-specific project to the all-projects-list
        foreach ($userProjects as $userProject) {
            $projects['all'][] = $userProject['project'];
        }

        // Add each global project to the all-projects-list
        foreach ($globalProjects as $globalProject) {
            $projects['all'][] = [
                    'id'     => $globalProject->getId(),
                    'name'   => $globalProject->getName(),
                    'jiraId' => $globalProject->getJiraId(),
            ];
        }

        // Sort projects by name for each customer
        foreach ($projects as &$project) {
            usort($project, $this->sortProjectsByName(...));
        }

        return $projects;
    }


    /**
     * Returns projects for given user, and optionally for given customer.
     *
     * @return array[]
     * @throws \ReflectionException
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
            if (is_object($project)) {
                $data[] = ['project' => $project->toArray()];
            }
        }

        return $data;
    }

    /**
     * @return Project[]
     */
    public function findByCustomer(int $customerId = 0)
    {
        /** @var Project[] $result */
        $result = $this->createQueryBuilder('project')
            ->where('project.global = 1 OR customer.id = :customerId')
            ->setParameter('customerId', $customerId)
            ->leftJoin('project.customer', 'customer')
            ->leftJoin('customer.teams', 'team')
            ->leftJoin('team.users', 'user')
            ->getQuery()
            ->execute();

        return $result;
    }

    /**
     * @param $jiraId
     * @return false|int
     */
    public function isValidJiraPrefix($jiraId): int|false
    {
        return preg_match('/^([A-Z]+[A-Z0-9]*[, ]*)*$/', (string) $jiraId);
    }
}

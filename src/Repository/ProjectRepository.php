<?php

namespace App\Repository;

use ReflectionException;
use App\Entity\Project;
use Doctrine\ORM\EntityRepository;

/**
 * Class ProjectRepository
 * @package App\Repository
 */
class ProjectRepository extends EntityRepository
{
    /**
     * @param string $a
     * @param string $b
     * @return int
     */
    public function sortProjectsByName($a, $b)
    {
        return strcasecmp($a['name'], $b['name']);
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
     * @param int $userId
     * @param array $customers
     * @return array[][]
     * @throws ReflectionException
     */
    public function getProjectStructure(int $userId, array $customers)
    {
        /* @var $globalProjects Project[] */
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
            usort($customerProjects, [$this, 'sortProjectsByName']);
        }

        return $projects;
    }


    /**
     * Returns projects for given user, and optionally for given customer.
     *
     * @return array[]
     * @throws ReflectionException
     */
    public function getProjectsByUser(int $userId, int $customerId = 0)
    {
        $qb = $this->createQueryBuilder('project')
            ->where('customer.global = 1 OR user.id = :userId')
            ->setParameter('userId', $userId);

        if ($customerId > 0) {
            $qb->andWhere('project.global = 1 OR customer.id = :customerId')
                ->setParameter('customerId', $customerId);
        }

        /** @var Project[] $result */
        $result = $qb->leftJoin('project.customer', 'customer')
            ->leftJoin('customer.teams', 'team')
            ->leftJoin('team.users', 'user')
            ->getQuery()
            ->execute();

        $data = [];
        foreach ($result as $project) {
            $data[] = ['project' => $project->toArray()];
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
     */
    public function isValidJiraPrefix($jiraId): int|false
    {
        return preg_match('/^([A-Z]+[A-Z0-9]*[, ]*)*$/', $jiraId);
    }
}

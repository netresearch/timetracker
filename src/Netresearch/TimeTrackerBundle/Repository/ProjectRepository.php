<?php

namespace Netresearch\TimeTrackerBundle\Repository;

use Netresearch\TimeTrackerBundle\Entity\Project;
use Netresearch\TimeTrackerBundle\Helper\TimeHelper;
use Doctrine\ORM\EntityRepository;

class ProjectRepository extends EntityRepository
{
    public function sortProjectsByName($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    }

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
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getProjectStructure($userId, array $customers)
    {
        /* @var $globalProjects Project[] */
        $globalProjects = $this->getGlobalProjects();
        $userProjects   = $this->getProjectsByUser($userId, null);

        $projects = [];
        foreach ($customers as $customer) {

            // Restructure customer-specific projects
            foreach ($userProjects as $project) {
                if ($customer['customer']['id'] == $project['project']['customer']) {
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
        foreach($projects AS &$customerProjects) {
            usort($customerProjects, [$this, 'sortProjectsByName']);
        }

        return $projects;
    }


    /**
     * @param $userId
     * @param null $customerId
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getProjectsByUser($userId, $customerId = null)
    {
        $connection = $this->getEntityManager()->getConnection();

        /* May god help us... */
        $sql = array();
        $sql['select'] = "SELECT DISTINCT p.*";
        $sql['from'] = "FROM projects p";
        $sql['join_c'] = "LEFT JOIN customers c ON p.customer_id = c.id";
        $sql['join_tc'] = "LEFT JOIN teams_customers tc ON tc.customer_id = c.id";
        $sql['join_tu'] = "LEFT JOIN teams_users tu ON tc.team_id = tu.team_id";
        $sql['where_user'] = "WHERE (c.global=1 OR tu.user_id = %d)";
        if ((int) $customerId > 0) {
            $sql['where_customer'] = "AND (p.customer_id = %d OR p.global = 1)";
        }
        $sql['order'] = "ORDER BY p.name ASC";

        $stmt = $connection->query(sprintf(implode(" ", $sql), $userId, $customerId));

        return $this->findByQuery($stmt);
    }

    /**
     * @param int $customerId
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     */
    public function findAll($customerId = 0)
    {
        $connection = $this->getEntityManager()->getConnection();

        $sql = array();
        $sql['select'] = "SELECT DISTINCT *";
        $sql['from'] = "FROM projects p";

        if ((int) $customerId > 0) {
            $sql['where'] = 'WHERE p.customer_id = ' . (int) $customerId
                            . ' OR p.global=1';
        }

        $sql['order'] = "ORDER BY p.name ASC";

        $stmt = $connection->query(implode(" ", $sql));

        return $this->findByQuery($stmt);
    }

    /**
     * @param \Doctrine\DBAL\Driver\Statement $stmt
     * @return array
     */
    protected function findByQuery(\Doctrine\DBAL\Driver\Statement $stmt)
    {
        $projects = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $data = [];
        foreach ($projects as $project) {
            $data[] = ['project' => [
                'id'            => $project['id'],
                'name'          => $project['name'],
                'jiraId'        => $project['jira_id'],
                'ticket_system' => $project['ticket_system'],
                'customer'      => $project['customer_id'],
                'active'        => $project['active'],
                'global'        => $project['global'],
                'estimation'    => $project['estimation'],
                'estimationText'=> TimeHelper::minutes2readable($project['estimation'], false),
                'billing'       => $project['billing'],
                'cost_center'   => $project['cost_center'],
                'offer'         => $project['offer'],
                'project_lead'  => $project['project_lead_id'],
                'technical_lead'=> $project['technical_lead_id'],
                'additionalInformationFromExternal' => $project['additional_information_from_external'],
                'internalJiraProjectKey' => $project['internal_jira_project_key'],
                'internalJiraTicketSystem' => $project['internal_jira_ticket_system'],
            ]];
        }

        return $data;
    }

    public function isValidJiraPrefix($jiraId)
    {
        return preg_match('/^([A-Z]+[A-Z0-9]*[, ]*)*$/', $jiraId);
    }
}

<?php

namespace App\Services;

use App\Entity\Project;
use App\Helper\JiraOAuthApi;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RouterInterface;

class SubticketSyncService
{
    /**
     * @var ManagerRegistry
     */
    private $doctrine;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ManagerRegistry $doctrine
     * @param RouterInterface $router
     * @param LoggerInterface $logger
     */
    public function __construct(
        ManagerRegistry $doctrine,
        RouterInterface $router,
        LoggerInterface $logger
    ) {
        $this->doctrine = $doctrine;
        $this->router = $router;
        $this->logger = $logger;
    }

    /**
     * Fetch subtickets from Jira and update the project record's "subtickets" field.
     *
     * The project lead user's Jira tokens are used for access.
     *
     * @return array Array of subticket keys
     *
     * @throws \Exception When something goes wrong.
     *                    Exception codes are sensible HTTP status codes
     */
    public function syncProjectSubtickets($projectOrProjectId)
    {
        if ($projectOrProjectId instanceof Project) {
            $project = $projectOrProjectId;
        } else {
            $project = $this->doctrine
                ->getRepository(\App\Entity\Project::class)
                ->find($projectOrProjectId);
        }
        if (!$project) {
            throw new \Exception('Project does not exist', 404);
        }

        $ticketSystem = $project->getTicketSystem();
        if (!$ticketSystem) {
            throw new \Exception('No ticket system configured for project', 400);
        }

        $mainTickets = $project->getJiraTicket();
        if ($mainTickets === null) {
            if ($project->getSubtickets() != '') {
                $project->setSubtickets([]);

                $em = $this->doctrine->getManager();
                $em->persist($project);
                $em->flush();
            }
            return [];
        }

        $userWithJiraAccess = $project->getProjectLead();
        if (!$userWithJiraAccess) {
            throw new \Exception(
                'Project has no lead user: ' . $project->getName(),
                400
            );
        }
        $token = $userWithJiraAccess->getTicketSystemAccessToken($ticketSystem);
        if (!$token) {
            throw new \Exception(
                'Project user has no token for ticket system: '
                . $userWithJiraAccess->getUsername()
                . '@' . $project->getName(),
                400
            );
        }

        // Create the JiraOAuthApi with our service's dependencies
        $jiraOAuthApi = new JiraOAuthApi(
            $userWithJiraAccess,
            $ticketSystem,
            $this->doctrine,
            $this->router
        );

        $mainTickets = array_map('trim', explode(',', $mainTickets));
        $allSubtickets = [];
        foreach ($mainTickets as $mainTicket) {
            //we want to make it easy to find matching tickets,
            // so we put the main ticket in the subticket list as well
            $allSubtickets[] = $mainTicket;
            $allSubtickets = array_merge(
                $allSubtickets, $jiraOAuthApi->getSubtickets($mainTicket)
            );
        }
        natcasesort($allSubtickets);

        $project->setSubtickets($allSubtickets);
        $em = $this->doctrine->getManager();
        $em->persist($project);
        $em->flush();

        return array_values($allSubtickets);
    }
}

<?php

namespace App\Services;

use App\Entity\Project;
use App\Helper\JiraOAuthApi;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\RouterInterface;

class SubticketSyncService
{
    public function __construct(private readonly ManagerRegistry $managerRegistry, private readonly RouterInterface $router)
    {
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
    public function syncProjectSubtickets($projectOrProjectId): array
    {
        if ($projectOrProjectId instanceof Project) {
            $project = $projectOrProjectId;
        } else {
            $project = $this->managerRegistry
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

                $em = $this->managerRegistry->getManager();
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
            $this->managerRegistry,
            $this->router
        );

        $mainTickets = array_map('trim', explode(',', (string) $mainTickets));
        $allSubtickets = [];
        foreach ($mainTickets as $mainTicket) {
            //we want to make it easy to find matching tickets,
            // so we put the main ticket in the subticket list as well
            $allSubtickets[] = $mainTicket;
            $allSubtickets = array_merge(
                $allSubtickets,
                $jiraOAuthApi->getSubtickets($mainTicket)
            );
        }

        natcasesort($allSubtickets);

        $project->setSubtickets($allSubtickets);
        $em = $this->managerRegistry->getManager();
        $em->persist($project);
        $em->flush();

        return array_values($allSubtickets);
    }
}

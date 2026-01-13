<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use Doctrine\Persistence\ManagerRegistry;
use Exception;

class SubticketSyncService
{
    public function __construct(private readonly ManagerRegistry $managerRegistry, private readonly JiraOAuthApiFactory $jiraOAuthApiFactory)
    {
    }

    /**
     * Fetch subtickets from Jira and update the project record's "subtickets" field.
     *
     * The project lead user's Jira tokens are used for access.
     *
     * @throws Exception When something goes wrong.
     *                   Exception codes are sensible HTTP status codes
     *
     * @return (mixed|string)[] Array of subticket keys
     *
     * @psalm-return list<mixed|string>
     */
    public function syncProjectSubtickets(int|Project $projectOrProjectId): array
    {
        if ($projectOrProjectId instanceof Project) {
            $project = $projectOrProjectId;
        } else {
            $project = $this->managerRegistry
                ->getRepository(Project::class)
                ->find($projectOrProjectId);
        }

        if (!$project instanceof Project) {
            throw new Exception('Project does not exist', 404);
        }

        $ticketSystem = $project->getTicketSystem();
        if (!$ticketSystem instanceof TicketSystem) {
            throw new Exception('No ticket system configured for project', 400);
        }

        $mainTickets = $project->getJiraTicket();
        if (null === $mainTickets) {
            if ('' !== ($project->getSubtickets() ?? '')) {
                $project->setSubtickets('');

                $em = $this->managerRegistry->getManager();
                $em->persist($project);
                $em->flush();
            }

            return [];
        }

        $userWithJiraAccess = $project->getProjectLead();
        if (!$userWithJiraAccess instanceof User) {
            throw new Exception('Project has no lead user: ' . $project->getName(), 400);
        }

        $token = $userWithJiraAccess->getTicketSystemAccessToken($ticketSystem);
        if (null === $token || '' === $token) {
            throw new Exception('Project user has no token for ticket system: ' . $userWithJiraAccess->getUsername() . '@' . $project->getName(), 400);
        }

        // Create the Jira API service with our service's dependencies
        $jiraOAuthApiService = $this->jiraOAuthApiFactory->create($userWithJiraAccess, $ticketSystem);

        $mainTickets = array_map(trim(...), explode(',', $mainTickets));
        $allSubtickets = [];
        foreach ($mainTickets as $mainTicket) {
            // we want to make it easy to find matching tickets,
            // so we put the main ticket in the subticket list as well
            $allSubtickets[] = $mainTicket;
            $allSubtickets = array_merge(
                $allSubtickets,
                $jiraOAuthApiService->getSubtickets($mainTicket),
            );
        }

        natcasesort($allSubtickets);

        // Convert array to comma-separated string for storage
        $project->setSubtickets(implode(',', $allSubtickets));
        $em = $this->managerRegistry->getManager();
        $em->persist($project);
        $em->flush();

        return array_values($allSubtickets);
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\SubticketSyncException;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use Doctrine\Persistence\ManagerRegistry;

class SubticketSyncService
{
    public function __construct(private readonly ManagerRegistry $managerRegistry, private readonly JiraOAuthApiFactory $jiraOAuthApiFactory, private readonly ClockInterface $clock)
    {
    }

    /**
     * Fetch subtickets from Jira and update the project record's "subtickets" field.
     *
     * The project lead user's Jira tokens are used for access.
     *
     * @throws SubticketSyncException When the project does not exist or its ticket system
     *                                setup is incomplete (no ticket system, no lead user,
     *                                no token). Exception codes are sensible HTTP status codes
     * @throws JiraApiException       When fetching subtickets from Jira fails
     *
     * @return list<string> Array of subticket keys
     */
    public function syncProjectSubtickets(int|Project $projectOrProjectId): array
    {
        $project = $this->resolveProject($projectOrProjectId);

        $ticketSystem = $project->getTicketSystem();
        if (!$ticketSystem instanceof TicketSystem) {
            throw new SubticketSyncException('No ticket system configured for project', 400);
        }

        $mainTickets = $project->getJiraTicket();
        if (null === $mainTickets) {
            // A ticket-system-bound project with no main ticket has no subtickets;
            // clear the list but still record that a sync ran.
            $project->setSubtickets('');
            $this->persistSynced($project);

            return [];
        }

        $userWithJiraAccess = $project->getProjectLead();
        if (!$userWithJiraAccess instanceof User) {
            throw new SubticketSyncException('Project has no lead user: ' . $project->getName(), 400);
        }

        $token = $userWithJiraAccess->getTicketSystemAccessToken($ticketSystem);
        if (null === $token || '' === $token) {
            throw new SubticketSyncException('Project user has no token for ticket system: ' . $userWithJiraAccess->getUsername() . '@' . $project->getName(), 400);
        }

        // Create the Jira API service with our service's dependencies
        $jiraOAuthApiService = $this->jiraOAuthApiFactory->create($userWithJiraAccess, $ticketSystem);

        $allSubtickets = $this->collectSubtickets($jiraOAuthApiService, array_map(trim(...), explode(',', $mainTickets)));

        natcasesort($allSubtickets);

        // Convert array to comma-separated string for storage
        $project->setSubtickets(implode(',', $allSubtickets));
        $this->persistSynced($project);

        return array_values($allSubtickets);
    }

    /**
     * Stamp the sync time and persist the project. Reached on any successful
     * completion — both the normal path and the "no main ticket → empty list"
     * path; the missing ticket-system / lead / token guards throw before this.
     */
    private function persistSynced(Project $project): void
    {
        $project->setSubticketsSyncedAt($this->clock->now());
        $em = $this->managerRegistry->getManager();
        $em->persist($project);
        $em->flush();
    }

    /**
     * @throws SubticketSyncException When the project does not exist
     */
    private function resolveProject(int|Project $projectOrProjectId): Project
    {
        $project = $projectOrProjectId instanceof Project
            ? $projectOrProjectId
            : $this->managerRegistry->getRepository(Project::class)->find($projectOrProjectId);

        if (!$project instanceof Project) {
            throw new SubticketSyncException('Project does not exist', 404);
        }

        return $project;
    }

    /**
     * Fetches the subtickets of all main tickets from Jira.
     *
     * @param list<string> $mainTickets
     *
     * @throws JiraApiException When fetching subtickets from Jira fails
     *
     * @return list<string>
     */
    private function collectSubtickets(JiraOAuthApiService $jiraOAuthApiService, array $mainTickets): array
    {
        $allSubtickets = [];
        foreach ($mainTickets as $mainTicket) {
            // we want to make it easy to find matching tickets,
            // so we put the main ticket in the subticket list as well
            $allSubtickets[] = $mainTicket;
            foreach ($jiraOAuthApiService->getSubtickets($mainTicket) as $subticket) {
                if ('' !== $subticket) {
                    $allSubtickets[] = $subticket;
                }
            }
        }

        return $allSubtickets;
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Repository\ProjectRepository;
use App\ValueObject\Sync\ProjectResolution;

use function array_map;
use function count;
use function explode;
use function in_array;
use function sprintf;
use function strcasecmp;
use function strstr;
use function trim;

/**
 * Maps a Jira issue key to the owning TT project (ADR-023 import; ADR-020 precedence:
 * exact subticket match wins over jira_id prefix match). Ambiguity parks the worklog.
 */
class TicketProjectResolver
{
    /** @var array<int, list<Project>> */
    private array $projectsByTicketSystem = [];

    public function __construct(private readonly ProjectRepository $projectRepository)
    {
    }

    public function resolve(string $issueKey, TicketSystem $ticketSystem): ProjectResolution
    {
        $projects = $this->projectsFor($ticketSystem);

        $subticketOwners = $this->subticketOwners($projects, $issueKey);
        if ([] !== $subticketOwners) {
            return $this->decide(
                $subticketOwners,
                'exact subticket match',
                sprintf('ambiguous: %d projects list %s as subticket', count($subticketOwners), $issueKey),
            );
        }

        $prefix = strstr($issueKey, '-', true);
        if (false === $prefix || '' === $prefix) {
            return new ProjectResolution(null, sprintf('no project resolvable: %s has no key prefix', $issueKey));
        }

        $prefixOwners = $this->prefixOwners($projects, $prefix);
        if ([] !== $prefixOwners) {
            return $this->decide(
                $prefixOwners,
                'jira_id prefix match',
                sprintf('ambiguous: %d projects claim prefix %s', count($prefixOwners), $prefix),
            );
        }

        return new ProjectResolution(null, sprintf('no project for prefix %s on this ticket system', $prefix));
    }

    /**
     * @param non-empty-list<Project> $candidates
     */
    private function decide(array $candidates, string $matchReason, string $ambiguousReason): ProjectResolution
    {
        return 1 === count($candidates)
            ? new ProjectResolution($candidates[0], $matchReason)
            : new ProjectResolution(null, $ambiguousReason);
    }

    /**
     * @param list<Project> $projects
     *
     * @return list<Project>
     */
    private function subticketOwners(array $projects, string $issueKey): array
    {
        $owners = [];
        foreach ($projects as $project) {
            $subtickets = array_map(trim(...), explode(',', $project->getSubtickets() ?? ''));
            foreach ($subtickets as $subticket) {
                if ('' !== $subticket && 0 === strcasecmp($subticket, $issueKey)) {
                    $owners[] = $project;
                    break;
                }
            }
        }

        return $owners;
    }

    /**
     * @param list<Project> $projects
     *
     * @return list<Project>
     */
    private function prefixOwners(array $projects, string $prefix): array
    {
        $owners = [];
        foreach ($projects as $project) {
            $jiraId = $project->getJiraId();
            if (null === $jiraId) {
                continue;
            }
            if ('' === $jiraId) {
                continue;
            }

            $allowedPrefixes = array_map(trim(...), explode(',', $jiraId));
            if (in_array($prefix, $allowedPrefixes, true)) {
                $owners[] = $project;
            }
        }

        return $owners;
    }

    /**
     * @return list<Project>
     */
    private function projectsFor(TicketSystem $ticketSystem): array
    {
        $key = (int) $ticketSystem->getId();

        return $this->projectsByTicketSystem[$key] ??= $this->projectRepository->findByTicketSystem($ticketSystem);
    }
}

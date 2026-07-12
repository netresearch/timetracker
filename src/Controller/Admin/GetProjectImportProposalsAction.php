<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\Sync\ProjectImportProposal;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\SyncRunRepository;
use App\Service\Sync\ProjectImportProposalService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_diff;
use function array_map;
use function array_values;

/**
 * Review screen backend (ADR-026 P1): list the unresolved Jira prefixes parked
 * by recent sync runs of a ticket system, each with its derived Customer +
 * Project proposal (Tempo account → category → keyword precedence). Nothing is
 * persisted — the admin confirms or overrides each row via the confirm endpoint.
 *
 * ROLE_ADMIN gates admin and PL alike here: a PL user carries ROLE_ADMIN
 * (User::getRoles, v4 compat), so this matches "admin or PL" without an
 * expression gate.
 */
final readonly class GetProjectImportProposalsAction
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private SyncRunRepository $syncRunRepository,
        private ProjectRepository $projectRepository,
        private ProjectImportProposalService $projectImportProposalService,
    ) {
    }

    #[Route(path: '/project-import/proposals', name: 'project_import_proposals', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $ticketSystemId = $request->query->getInt('ticketSystem');
        if ($ticketSystemId <= 0) {
            return new JsonResponse(['message' => 'A ticketSystem id is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticketSystem = $this->managerRegistry->getRepository(TicketSystem::class)->find($ticketSystemId);
        if (!$ticketSystem instanceof TicketSystem) {
            return new JsonResponse(['message' => 'Ticket system not found.'], Response::HTTP_NOT_FOUND);
        }

        // Parked prefixes still list a prefix a later import already resolved
        // (the historical sync item is never rewritten). Drop the prefixes a
        // project now owns on this ticket system, so an imported project stops
        // being re-proposed — and its Jira/Tempo derivation is skipped too.
        $prefixes = $this->syncRunRepository->findUnresolvedProjectPrefixes($ticketSystem);
        $owned = $this->projectRepository->findOwnedJiraIds($prefixes, $ticketSystem);
        if ([] !== $owned) {
            $prefixes = array_values(array_diff($prefixes, $owned));
        }

        $proposals = $this->projectImportProposalService->proposeForKeys($prefixes, $ticketSystem, $user);

        return new JsonResponse([
            'ticket_system_id' => $ticketSystemId,
            'proposals' => array_map($this->toArray(...), $proposals),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(ProjectImportProposal $projectImportProposal): array
    {
        return [
            'jira_key' => $projectImportProposal->jiraKey,
            'project_id' => $projectImportProposal->projectId,
            'project_name' => $projectImportProposal->projectName,
            'jira_id_prefix' => $projectImportProposal->jiraIdPrefix,
            'derived_customer_name' => $projectImportProposal->derivedCustomerName,
            'derived_customer_key' => $projectImportProposal->derivedCustomerKey,
            'derivation_source' => $projectImportProposal->derivationSource,
            'candidate_customers' => $projectImportProposal->candidateCustomers,
        ];
    }
}

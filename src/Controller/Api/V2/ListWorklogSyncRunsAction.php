<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\SyncRunDto;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Repository\SyncRunRepository;
use App\Security\ApiToken\RequireScope;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

use function array_map;
use function count;
use function max;
use function min;

/**
 * The latest worklog sync runs, newest first, without their per-worklog items
 * (ADR-023 §6 run history). Non-admins see only runs they triggered; admins
 * see all and may filter by ticket system.
 */
final readonly class ListWorklogSyncRunsAction
{
    public function __construct(
        private SyncRunRepository $syncRunRepository,
        private ManagerRegistry $managerRegistry,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[RequireScope('sync:read')]
    #[Route(path: '/api/v2/worklog-sync/runs', name: 'api_v2_worklog_sync_run_list', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $ticketSystem = null;
        $ticketSystemId = $request->query->getInt('ticket_system_id');
        if ($ticketSystemId > 0) {
            $ticketSystem = $this->managerRegistry->getRepository(TicketSystem::class)->find($ticketSystemId);
            if (!$ticketSystem instanceof TicketSystem) {
                return new JsonResponse(['runs' => [], 'count' => 0]);
            }
        }

        $limit = max(1, min(100, $request->query->getInt('limit', 20)));

        // Non-admins are filtered to their own runs in the query, so the limit
        // bounds their own runs (post-limit filtering could return zero).
        $ownerFilter = $this->authorizationChecker->isGranted('ROLE_ADMIN') ? null : $user;
        $runs = $this->syncRunRepository->findLatest($limit, $ticketSystem, $ownerFilter);

        $dtos = array_map(
            static fn (SyncRun $syncRun): SyncRunDto => SyncRunDto::fromEntity($syncRun, false),
            $runs,
        );

        return new JsonResponse(['runs' => $dtos, 'count' => count($dtos)]);
    }
}

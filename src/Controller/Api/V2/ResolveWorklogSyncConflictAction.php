<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\ResolveConflictDto;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Repository\WorklogSyncStateRepository;
use App\Security\ApiToken\RequireScope;
use App\Service\Sync\ConflictResolutionService;
use App\Service\Sync\SyncRunAuthorization;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Resolve a parked worklog sync state by picking a winner (ADR-023 §6):
 * local wins via a forced lease-era write, remote wins by pulling the live
 * remote — or deleting the local entry when the remote is gone. Non-admins
 * may only resolve conflicts on their own entries.
 */
final readonly class ResolveWorklogSyncConflictAction
{
    public function __construct(
        private WorklogSyncStateRepository $worklogSyncStateRepository,
        private ConflictResolutionService $conflictResolutionService,
        private AuthorizationCheckerInterface $authorizationChecker,
        private SyncRunAuthorization $syncRunAuthorization,
    ) {
    }

    #[RequireScope('sync:write')]
    #[Route(path: '/api/v2/worklog-sync/conflicts/{id}/resolve', name: 'api_v2_worklog_sync_conflict_resolve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function __invoke(int $id, #[MapRequestPayload] ResolveConflictDto $resolveConflictDto, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $state = $this->worklogSyncStateRepository->findParkedById($id);
        if (!$state instanceof WorklogSyncState) {
            return new JsonResponse(['message' => 'Conflict not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->syncRunAuthorization->canResolve($user, $this->authorizationChecker->isGranted('ROLE_ADMIN'), $state)) {
            return new JsonResponse(['message' => 'Not your conflict.'], Response::HTTP_FORBIDDEN);
        }

        $resolutionResult = $this->conflictResolutionService->resolve($state, $resolveConflictDto->winner, $user);
        if (!$resolutionResult->resolved) {
            return new JsonResponse(['message' => $resolutionResult->reason], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['resolved' => true, 'action' => $resolutionResult->action, 'conflict_id' => $id]);
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\SyncConflictDto;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Repository\WorklogSyncStateRepository;
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

/**
 * Parked worklog sync states — conflicts and orphans awaiting a winner
 * (ADR-023 §6). Non-admins see only their own entries; admins see all and
 * may filter by username.
 */
final readonly class ListWorklogSyncConflictsAction
{
    public function __construct(
        private WorklogSyncStateRepository $worklogSyncStateRepository,
        private ManagerRegistry $managerRegistry,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[RequireScope('sync:read')]
    #[Route(path: '/api/v2/worklog-sync/conflicts', name: 'api_v2_worklog_sync_conflicts', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return $this->respond($this->worklogSyncStateRepository->findParked($user));
        }

        $filterUser = null;
        $username = $request->query->getString('user');
        if ('' !== $username) {
            $filterUser = $this->managerRegistry->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$filterUser instanceof User) {
                return new JsonResponse(['message' => 'Unknown user.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return $this->respond($this->worklogSyncStateRepository->findParked($filterUser));
    }

    /**
     * @param list<WorklogSyncState> $states
     */
    private function respond(array $states): JsonResponse
    {
        $conflicts = array_map(
            SyncConflictDto::fromEntity(...),
            $states,
        );

        return new JsonResponse(['conflicts' => $conflicts, 'count' => count($conflicts)]);
    }
}

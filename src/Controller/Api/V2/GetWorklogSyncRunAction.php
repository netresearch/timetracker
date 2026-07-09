<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\SyncRunDto;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Repository\SyncRunRepository;
use App\Security\ApiToken\RequireScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * One worklog sync run with its per-worklog findings (ADR-023 §6).
 * Non-admins see only runs they triggered themselves.
 */
final readonly class GetWorklogSyncRunAction
{
    public function __construct(
        private SyncRunRepository $syncRunRepository,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[RequireScope('sync:read')]
    #[Route(path: '/api/v2/worklog-sync/runs/{id}', name: 'api_v2_worklog_sync_run_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(int $id, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $syncRun = $this->syncRunRepository->find($id);
        if (!$syncRun instanceof SyncRun) {
            return new JsonResponse(['message' => 'Run not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->authorizationChecker->isGranted('ROLE_ADMIN') && $syncRun->getTriggeredBy()?->getId() !== $user->getId()) {
            return new JsonResponse(['message' => 'Not your run.'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(SyncRunDto::fromEntity($syncRun));
    }
}

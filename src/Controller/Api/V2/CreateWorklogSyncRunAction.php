<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\SyncRunDto;
use App\Dto\WorklogSyncRunDto;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use App\Service\Sync\SyncRunAuthorization;
use App\Service\Sync\SyncRunRequestMapper;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Start a worklog verify/import/sync run against a ticket system (ADR-023 §6,
 * amended). Runs execute inline — the response carries the finished run with
 * its counters and findings. Non-admins may verify themselves, import only
 * their own worklogs, and sync only themselves; a PL/admin caller may sync a
 * named target under their own token.
 */
final readonly class CreateWorklogSyncRunAction
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private AuthorizationCheckerInterface $authorizationChecker,
        private SyncRunAuthorization $syncRunAuthorization,
        private SyncRunRequestMapper $syncRunRequestMapper,
    ) {
    }

    #[RequireScope('sync:write')]
    #[Route(path: '/api/v2/worklog-sync/runs', name: 'api_v2_worklog_sync_run_create', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] WorklogSyncRunDto $worklogSyncRunDto, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $ticketSystem = $this->managerRegistry->getRepository(TicketSystem::class)->find($worklogSyncRunDto->ticket_system_id);
        if (!$ticketSystem instanceof TicketSystem) {
            return new JsonResponse(['message' => 'Ticket system not found.'], Response::HTTP_NOT_FOUND);
        }

        $denied = $this->authorize($worklogSyncRunDto, $user);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }

        try {
            [$from, $to] = $this->syncRunRequestMapper->parseRange($worklogSyncRunDto);
        } catch (Exception) {
            return new JsonResponse(['message' => 'Invalid date in from/to (expected Y-m-d).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ('import' === $worklogSyncRunDto->type && null === $worklogSyncRunDto->default_activity_id) {
            return new JsonResponse(['message' => 'default_activity_id is required for import runs.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $syncTarget = null;
        if ('sync' === $worklogSyncRunDto->type && [] !== $worklogSyncRunDto->users) {
            $syncTarget = $this->managerRegistry->getRepository(User::class)->findOneBy(['username' => $worklogSyncRunDto->users[0]]);
            if (!$syncTarget instanceof User) {
                return new JsonResponse(['message' => 'Target user not found.'], Response::HTTP_NOT_FOUND);
            }
        }

        $syncRun = $this->syncRunRequestMapper->dispatch($worklogSyncRunDto, $user, $ticketSystem, $from, $to, $syncTarget);

        return new JsonResponse(SyncRunDto::fromEntity($syncRun), Response::HTTP_CREATED);
    }

    /**
     * The authorization matrix (ADR-023 §6, amended): admins may start
     * anything; non-admins may verify themselves, import only their own
     * username, and sync only themselves — syncing a named target needs
     * PL/admin.
     */
    private function authorize(WorklogSyncRunDto $worklogSyncRunDto, User $user): ?JsonResponse
    {
        $isAdmin = $this->authorizationChecker->isGranted('ROLE_ADMIN');
        if ($this->syncRunAuthorization->canTrigger($user, $isAdmin, $worklogSyncRunDto->type, $worklogSyncRunDto->users)) {
            return null;
        }

        $message = 'sync' === $worklogSyncRunDto->type
            ? 'Admin role required to sync another user.'
            : 'Non-admin imports are limited to your own user.';

        return new JsonResponse(['message' => $message], Response::HTTP_FORBIDDEN);
    }
}

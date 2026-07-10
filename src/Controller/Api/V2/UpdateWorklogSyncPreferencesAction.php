<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\WorklogSyncPreferenceDto;
use App\Dto\WorklogSyncPreferencesDto;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Security\ApiToken\RequireScope;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Set the caller's own worklog sync opt-in flags for one connected Jira ticket
 * system (ADR-023 amendment). Self only: it always writes the caller's own
 * UserTicketsystem row. `sync_all` (PO "sync everything I can access") is
 * accepted only from a PL/admin caller.
 */
final readonly class UpdateWorklogSyncPreferencesAction
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private EntityManagerInterface $entityManager,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[RequireScope('sync:write')]
    #[Route(path: '/api/v2/worklog-sync/preferences', name: 'api_v2_worklog_sync_preferences_update', methods: ['PUT'])]
    public function __invoke(#[MapRequestPayload] WorklogSyncPreferencesDto $worklogSyncPreferencesDto, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $ticketSystem = $this->managerRegistry->getRepository(TicketSystem::class)->find($worklogSyncPreferencesDto->ticket_system_id);
        if (!$ticketSystem instanceof TicketSystem) {
            return new JsonResponse(['message' => 'Ticket system not found.'], Response::HTTP_NOT_FOUND);
        }

        $userTicketsystem = $this->findConnection($user, $ticketSystem);
        if (!$userTicketsystem instanceof UserTicketsystem) {
            return new JsonResponse(['message' => 'No connection to this ticket system.'], Response::HTTP_NOT_FOUND);
        }

        if (null !== $worklogSyncPreferencesDto->sync_all) {
            if (!$this->canSyncAll()) {
                return new JsonResponse(['message' => 'Project-lead or admin role required to sync all worklogs.'], Response::HTTP_FORBIDDEN);
            }

            $userTicketsystem->setSyncAll($worklogSyncPreferencesDto->sync_all);
        }

        $userTicketsystem->setSyncEnabled($worklogSyncPreferencesDto->sync_enabled);
        $this->entityManager->flush();

        return new JsonResponse(WorklogSyncPreferenceDto::fromEntity($userTicketsystem));
    }

    private function findConnection(User $user, TicketSystem $ticketSystem): ?UserTicketsystem
    {
        foreach ($user->getUserTicketsystems() as $userTicketsystem) {
            if ($userTicketsystem->getTicketSystem() === $ticketSystem) {
                return $userTicketsystem;
            }
        }

        return null;
    }

    private function canSyncAll(): bool
    {
        if ($this->authorizationChecker->isGranted('ROLE_PL')) {
            return true;
        }

        return $this->authorizationChecker->isGranted('ROLE_ADMIN');
    }
}

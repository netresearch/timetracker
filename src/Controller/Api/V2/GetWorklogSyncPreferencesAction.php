<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\WorklogSyncPreferenceDto;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Security\ApiToken\RequireScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The caller's own worklog sync opt-in flags per connected Jira ticket system
 * (ADR-023 amendment) — the read side of the Settings toggle. `can_sync_all`
 * tells the UI whether to offer the PO "sync everything I can access" toggle.
 */
final readonly class GetWorklogSyncPreferencesAction
{
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[RequireScope('sync:read')]
    #[Route(path: '/api/v2/worklog-sync/preferences', name: 'api_v2_worklog_sync_preferences_get', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $preferences = [];
        foreach ($user->getUserTicketsystems() as $userTicketsystem) {
            if (!$userTicketsystem instanceof UserTicketsystem) {
                continue;
            }
            if ($userTicketsystem->getAvoidConnection()) {
                continue;
            }
            if (!$userTicketsystem->getTicketSystem() instanceof TicketSystem) {
                continue;
            }

            $preferences[] = WorklogSyncPreferenceDto::fromEntity($userTicketsystem);
        }

        return new JsonResponse([
            'preferences' => $preferences,
            'can_sync_all' => $this->canSyncAll(),
        ]);
    }

    private function canSyncAll(): bool
    {
        if ($this->authorizationChecker->isGranted('ROLE_PL')) {
            return true;
        }

        return $this->authorizationChecker->isGranted('ROLE_ADMIN');
    }
}

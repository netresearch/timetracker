<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\UserDto;
use App\Security\ApiToken\RequireScope;
use App\Service\AdminOnboardingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Activate or deactivate (offboard) a user (ADR-022 Phase 3). Deactivation is
 * the offboarding: UserChecker refuses logins and token use for inactive
 * accounts. Both gates: admin role AND, for tokens, the users:write scope.
 */
final readonly class SetUserActiveAction
{
    public function __construct(private AdminOnboardingService $adminOnboardingService)
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[RequireScope('users:write')]
    #[Route(path: '/api/v2/users/{id}/{action}', name: 'api_v2_users_active', requirements: ['id' => '\d+', 'action' => 'activate|deactivate'], methods: ['POST'])]
    public function __invoke(int $id, string $action): JsonResponse
    {
        $user = $this->adminOnboardingService->setUserActive($id, 'activate' === $action);
        if (!$user instanceof UserDto) {
            return new JsonResponse(['message' => 'No user for id.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($user);
    }
}

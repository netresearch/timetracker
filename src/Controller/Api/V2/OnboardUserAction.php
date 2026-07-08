<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\UserOnboardDto;
use App\Security\ApiToken\RequireScope;
use App\Service\AdminOnboardingService;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Onboard a user (ADR-022 Phase 3). Both gates: admin role AND, for tokens,
 * the users:write scope. The account authenticates against the directory —
 * no local password is set here (ADR-018).
 */
final readonly class OnboardUserAction
{
    public function __construct(private AdminOnboardingService $adminOnboardingService)
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[RequireScope('users:write')]
    #[Route(path: '/api/v2/users', name: 'api_v2_users_onboard', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] UserOnboardDto $userOnboardDto): JsonResponse
    {
        try {
            $user = $this->adminOnboardingService->onboardUser($userOnboardDto);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse(['message' => $invalidArgumentException->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($user, Response::HTTP_CREATED);
    }
}

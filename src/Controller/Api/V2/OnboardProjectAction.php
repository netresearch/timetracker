<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\ProjectOnboardDto;
use App\Security\ApiToken\RequireScope;
use App\Service\AdminOnboardingService;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Onboard a project (ADR-022 Phase 3). Both gates: admin role AND, for
 * tokens, the projects:write scope.
 */
final readonly class OnboardProjectAction
{
    public function __construct(private AdminOnboardingService $adminOnboardingService)
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[RequireScope('projects:write')]
    #[Route(path: '/api/v2/projects', name: 'api_v2_projects_onboard', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] ProjectOnboardDto $projectOnboardDto): JsonResponse
    {
        try {
            $project = $this->adminOnboardingService->onboardProject($projectOnboardDto);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse(['message' => $invalidArgumentException->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($project, Response::HTTP_CREATED);
    }
}

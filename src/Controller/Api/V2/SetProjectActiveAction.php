<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\ProjectDto;
use App\Security\ApiToken\RequireScope;
use App\Service\AdminOnboardingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Activate or deactivate (offboard) a project (ADR-022 Phase 3). Both gates:
 * admin role AND, for tokens, the projects:write scope.
 */
final readonly class SetProjectActiveAction
{
    public function __construct(private AdminOnboardingService $adminOnboardingService)
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[RequireScope('projects:write')]
    #[Route(path: '/api/v2/projects/{id}/{action}', name: 'api_v2_projects_active', requirements: ['id' => '\d+', 'action' => 'activate|deactivate'], methods: ['POST'])]
    public function __invoke(int $id, string $action): JsonResponse
    {
        $project = $this->adminOnboardingService->setProjectActive($id, 'activate' === $action);
        if (!$project instanceof ProjectDto) {
            return new JsonResponse(['message' => 'No project for id.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($project);
    }
}

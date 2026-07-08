<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\CustomerDto;
use App\Security\ApiToken\RequireScope;
use App\Service\AdminOnboardingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Activate or deactivate (offboard) a customer (ADR-022 Phase 3). Both gates:
 * admin role AND, for tokens, the customers:write scope.
 */
final readonly class SetCustomerActiveAction
{
    public function __construct(private AdminOnboardingService $adminOnboardingService)
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    #[RequireScope('customers:write')]
    #[Route(path: '/api/v2/customers/{id}/{action}', name: 'api_v2_customers_active', requirements: ['id' => '\d+', 'action' => 'activate|deactivate'], methods: ['POST'])]
    public function __invoke(int $id, string $action): JsonResponse
    {
        $customer = $this->adminOnboardingService->setCustomerActive($id, 'activate' === $action);
        if (!$customer instanceof CustomerDto) {
            return new JsonResponse(['message' => 'No customer for id.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($customer);
    }
}

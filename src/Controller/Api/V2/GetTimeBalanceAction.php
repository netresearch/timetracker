<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use App\Service\TimeBalanceService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The authenticated user's today/week/month worked-vs-target balance
 * (ADR-022). Supersedes GET /getTimeSummary; same numbers as the header
 * display and the get_time_balance MCP tool — all three consume
 * TimeBalanceService.
 */
final readonly class GetTimeBalanceAction
{
    public function __construct(private TimeBalanceService $timeBalanceService)
    {
    }

    #[RequireScope('reporting:read')]
    #[Route(path: '/api/v2/time-balance', name: 'api_v2_time_balance', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($this->timeBalanceService->forUser($user));
    }
}

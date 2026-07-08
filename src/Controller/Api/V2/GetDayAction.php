<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use App\Service\DaySummaryService;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The caller's own bookings for one day (default: today) plus the booked
 * total — the tracking grid's day view as data (ADR-022 Phase 2).
 */
final readonly class GetDayAction
{
    public function __construct(private DaySummaryService $daySummaryService)
    {
    }

    #[RequireScope('entries:read')]
    #[Route(path: '/api/v2/day', name: 'api_v2_day', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $date = $request->query->get('date');

        try {
            $summary = $this->daySummaryService->forUser($user, null !== $date ? (string) $date : null);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse(['message' => $invalidArgumentException->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($summary);
    }
}

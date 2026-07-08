<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\EntrySummaryDto;
use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use App\Service\EntrySummaryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Per-scope booking totals (customer/project/activity/ticket) plus estimate
 * verdict for one of the caller's own entries — the tracking UI's "Info" (I)
 * popup (ADR-022). Supersedes POST /getSummary. Owner-scoped: an entry the
 * caller does not own reads as not found (404, no existence disclosure).
 */
final readonly class GetEntrySummaryAction
{
    public function __construct(private EntrySummaryService $entrySummaryService)
    {
    }

    #[RequireScope('reporting:read')]
    #[Route(path: '/api/v2/entries/{id}/summary', name: 'api_v2_entry_summary', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(int $id, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $summary = $this->entrySummaryService->forEntry($id, (int) $user->getId());
        if (!$summary instanceof EntrySummaryDto) {
            return new JsonResponse(['message' => 'No entry for id.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($summary);
    }
}

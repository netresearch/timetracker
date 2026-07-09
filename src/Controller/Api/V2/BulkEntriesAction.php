<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Controller\Tracking\BulkEntryAction;
use App\Dto\BulkEntriesDto;
use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

use function trim;

/**
 * Bulk-fill a date range from a preset (ADR-022 Phase 4) — delegates to the
 * same BulkEntryAction as the UI's "Massen-Eintragung".
 */
final readonly class BulkEntriesAction
{
    public function __construct(private BulkEntryAction $bulkEntryAction)
    {
    }

    #[RequireScope('entries:write')]
    #[Route(path: '/api/v2/bulk-entries', name: 'api_v2_bulk_entries', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] BulkEntriesDto $bulkEntriesDto, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $request = new Request(request: [
            'preset' => (string) $bulkEntriesDto->preset_id,
            'startdate' => trim($bulkEntriesDto->start_date),
            'enddate' => trim($bulkEntriesDto->end_date),
            'starttime' => trim($bulkEntriesDto->start_time),
            'endtime' => trim($bulkEntriesDto->end_time),
            'usecontract' => $bulkEntriesDto->use_contract ? '1' : '0',
            'skipweekend' => $bulkEntriesDto->skip_weekend ? '1' : '0',
            'skipholidays' => $bulkEntriesDto->skip_holidays ? '1' : '0',
        ]);

        $response = ($this->bulkEntryAction)($request, $user);
        $message = trim((string) $response->getContent());
        $ok = $response->getStatusCode() < Response::HTTP_BAD_REQUEST;

        return new JsonResponse(
            $ok ? ['success' => true, 'message' => $message] : ['message' => '' !== $message ? $message : 'Bulk entry failed.'],
            $ok ? Response::HTTP_CREATED : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

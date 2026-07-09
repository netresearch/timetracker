<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Controller\Tracking\SaveEntryAction;
use App\Dto\EntryPatchDto;
use App\Dto\EntrySaveDto;
use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use App\Service\EntryUpdateService;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

use function is_array;
use function is_string;
use function json_decode;

/**
 * Partial edit of one of the caller's own time entries (ADR-022 Phase 4).
 * Owner-scoped: a foreign or unknown id answers 404. Delegates the merge to
 * EntryUpdateService and the persistence to SaveEntryAction (same path as the
 * UI and the update_entry MCP tool).
 */
final readonly class UpdateEntryAction
{
    public function __construct(
        private EntryUpdateService $entryUpdateService,
        private SaveEntryAction $saveEntryAction,
    ) {
    }

    #[RequireScope('entries:write')]
    #[Route(path: '/api/v2/entries/{id}', name: 'api_v2_entry_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function __invoke(int $id, #[MapRequestPayload] EntryPatchDto $entryPatchDto, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $dto = $this->entryUpdateService->mergedDto(
                entryId: $id,
                userId: (int) $user->getId(),
                projectId: $entryPatchDto->project_id,
                activityId: $entryPatchDto->activity_id,
                ticket: $entryPatchDto->ticket,
                description: $entryPatchDto->description,
                date: $entryPatchDto->date,
                durationMinutes: $entryPatchDto->durationMinutes,
                start: $entryPatchDto->start,
                end: $entryPatchDto->end,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse(['message' => $invalidArgumentException->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$dto instanceof EntrySaveDto) {
            return new JsonResponse(['message' => 'No entry for id.'], Response::HTTP_NOT_FOUND);
        }

        $response = ($this->saveEntryAction)($dto, $user);
        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            $body = json_decode((string) $response->getContent(), true);
            $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : 'Failed to update the entry.';

            return new JsonResponse(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(json_decode((string) $response->getContent(), true), Response::HTTP_OK);
    }
}

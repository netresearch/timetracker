<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\UserSettingsDto;
use App\Dto\UpdateUserSettingsDto;
use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use App\Service\Util\LocalizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Partial update of the authenticated user's account settings: only the
 * fields present in the payload are persisted ("not sent = unchanged").
 * This server-side guarantee replaces the old client-side preservation
 * logic for the disabled Personio opt-in (spec §6/§9).
 */
final readonly class UpdateSettingsAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LocalizationService $localizationService,
    ) {
    }

    #[RequireScope('settings:write')]
    #[Route(path: '/api/v2/settings', name: 'api_v2_settings_update', methods: ['PATCH'])]
    public function __invoke(#[MapRequestPayload] UpdateUserSettingsDto $dto, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        if (null !== $dto->show_empty_line) {
            $user->setShowEmptyLine($dto->show_empty_line);
        }

        if (null !== $dto->suggest_time) {
            $user->setSuggestTime($dto->suggest_time);
        }

        if (null !== $dto->show_future) {
            $user->setShowFuture($dto->show_future);
        }

        if (null !== $dto->min_entry_duration) {
            $user->setMinEntryDuration($dto->min_entry_duration);
        }

        if (null !== $dto->personio_sync_enabled) {
            $user->setPersonioSyncEnabled($dto->personio_sync_enabled);
        }

        if (null !== $dto->locale) {
            $user->setLocale($this->localizationService->normalizeLocale($dto->locale));
        }

        $this->entityManager->flush();

        return new JsonResponse(UserSettingsDto::fromUser($user));
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\UserSettingsDto;
use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The authenticated user's account settings (settings page, Account +
 * Sync sections). Self only — there is no per-user variant.
 */
final readonly class GetSettingsAction
{
    #[RequireScope('settings:read')]
    #[Route(path: '/api/v2/settings', name: 'api_v2_settings_get', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(UserSettingsDto::fromUser($user));
    }
}

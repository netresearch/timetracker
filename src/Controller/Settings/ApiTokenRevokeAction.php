<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\BaseController;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Repository\ApiTokenRepository;
use App\Service\ApiToken\ApiTokenService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Revoke one of the current user's API tokens (ADR-021 Phase 3). Ownership is
 * enforced by matching the token's user — a user can only revoke a token bound to
 * their own account, never another's by guessing an id. Revocation is idempotent
 * (the entity keeps the first revoked-at), so re-posting a revoked id still succeeds.
 */
final class ApiTokenRevokeAction extends BaseController
{
    public function __construct(
        private readonly ApiTokenRepository $apiTokens,
        private readonly ApiTokenService $apiTokenService,
    ) {
    }

    #[Route(path: '/settings/api-tokens/revoke', name: 'settings_api_tokens_revoke', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $id = (int) $request->getPayload()->get('id', 0);

        $token = $id > 0 ? $this->apiTokens->find($id) : null;
        if (!$token instanceof ApiToken || $token->getUser()->getId() !== $user->getId()) {
            $response = new JsonResponse(['success' => false, 'message' => $this->translate('That token could not be found.')]);
            $response->setStatusCode(Response::HTTP_NOT_FOUND);

            return $response;
        }

        $this->apiTokenService->revoke($token);

        return new JsonResponse(['success' => true]);
    }
}

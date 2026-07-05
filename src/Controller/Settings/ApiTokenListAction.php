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
use DateTimeInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The current user's API tokens for the Settings management list (ADR-021 Phase 3),
 * plus the scope taxonomy that drives the create-form picker. The token secret is
 * never returned — only its metadata (name, scopes, and the created/used/expiry
 * timestamps). Session-only by design: it declares no #[RequireScope], so a Bearer
 * token cannot reach it (RequireScopeSubscriber denies fail-closed) — auth-state
 * management stays off the token firewall (ADR-021 §7).
 */
final class ApiTokenListAction extends BaseController
{
    public function __construct(
        private readonly ApiTokenRepository $apiTokens,
        private readonly ApiTokenService $apiTokenService,
    ) {
    }

    #[Route(path: '/settings/api-tokens', name: 'settings_api_tokens_list', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'tokens' => array_map(
                static fn (ApiToken $token): array => [
                    'id' => $token->getId(),
                    'name' => $token->getName(),
                    'scopes' => $token->getScopes(),
                    'createdAt' => $token->getCreatedAt()->format(DateTimeInterface::ATOM),
                    'lastUsedAt' => $token->getLastUsedAt()?->format(DateTimeInterface::ATOM),
                    'expiresAt' => $token->getExpiresAt()?->format(DateTimeInterface::ATOM),
                    'revokedAt' => $token->getRevokedAt()?->format(DateTimeInterface::ATOM),
                ],
                $this->apiTokens->findByUser($user),
            ),
            // The picker is a resources × actions grid plus a wildcard, so the UI
            // gets the taxonomy (via the service, not the value object directly)
            // rather than a flat list it would have to re-split.
            ...$this->apiTokenService->scopeTaxonomy(),
        ]);
    }
}

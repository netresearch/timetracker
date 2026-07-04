<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\ApiToken;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Service\ApiToken\ApiTokenService;
use Override;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

use function assert;
use function strlen;
use function substr;

/**
 * Authenticates a request bearing a valid API personal access token (ADR-021).
 * Stateless: resolves the token by its SHA-256 hash, rejects if missing / expired
 * / revoked, records use (coarsely), and produces an ApiAccessToken carrying the
 * owning user's roles plus the token's scopes. A generic 401 is returned on
 * failure — no token-validity detail leaks.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly ApiTokenService $apiTokenService)
    {
    }

    public function supports(Request $request): bool
    {
        return ApiTokenRequestMatcher::hasBearerToken((string) $request->headers->get('Authorization'));
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        // Strip the "Bearer " scheme (case-insensitive); the rest is the tt_pat_… plaintext.
        $plaintext = substr((string) $request->headers->get('Authorization'), strlen(ApiTokenRequestMatcher::SCHEME));
        $apiToken = $this->apiTokenService->findActiveByPlaintext($plaintext);
        if (!$apiToken instanceof ApiToken) {
            throw new CustomUserMessageAuthenticationException('Invalid API token.');
        }

        $this->apiTokenService->recordUsage($apiToken);
        $user = $apiToken->getUser();

        $passport = new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user));
        $passport->setAttribute('scopes', $apiToken->getScopes());

        return $passport;
    }

    #[Override]
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();
        assert($user instanceof User);

        /** @var list<string> $scopes */
        $scopes = $passport->getAttribute('scopes') ?? [];

        return new ApiAccessToken($user, array_values($user->getRoles()), $scopes);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // let the request proceed to the controller
    }
}

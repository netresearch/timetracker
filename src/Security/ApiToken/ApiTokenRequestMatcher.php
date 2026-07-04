<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\ApiToken;

use App\Service\ApiToken\ApiTokenService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Claims a request for the stateless `api` firewall (ADR-021) iff it carries an
 * `Authorization: Bearer tt_pat_…` header. Everything else falls through to the
 * session-based `main` firewall, so the SPA is untouched.
 */
final readonly class ApiTokenRequestMatcher implements RequestMatcherInterface
{
    /** RFC 7235 auth-scheme names are case-insensitive; the token itself is exact. */
    public const string SCHEME = 'bearer ';

    public function matches(Request $request): bool
    {
        return self::hasBearerToken((string) $request->headers->get('Authorization'));
    }

    /**
     * Whether the header is a `Bearer` (any case) carrying a `tt_pat_` token.
     */
    public static function hasBearerToken(string $authorization): bool
    {
        return str_starts_with(strtolower($authorization), self::SCHEME)
            && str_starts_with(substr($authorization, strlen(self::SCHEME)), ApiTokenService::PREFIX);
    }
}

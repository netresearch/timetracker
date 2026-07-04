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

/**
 * Claims a request for the stateless `api` firewall (ADR-021) iff it carries an
 * `Authorization: Bearer tt_pat_…` header. Everything else falls through to the
 * session-based `main` firewall, so the SPA is untouched.
 */
final readonly class ApiTokenRequestMatcher implements RequestMatcherInterface
{
    private const string BEARER_PREFIX = 'Bearer ' . ApiTokenService::PREFIX;

    public function matches(Request $request): bool
    {
        return str_starts_with((string) $request->headers->get('Authorization'), self::BEARER_PREFIX);
    }
}

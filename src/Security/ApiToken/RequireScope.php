<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\ApiToken;

use Attribute;

/**
 * Declares the API scope a controller requires when reached via an API token
 * (ADR-021). Session (cookie) requests ignore it — scopes gate token auth only.
 * A data endpoint reachable by a token but WITHOUT this attribute is denied
 * (fail-closed), enforced by RequireScopeSubscriber and a coverage test.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class RequireScope
{
    public function __construct(public string $scope)
    {
    }
}

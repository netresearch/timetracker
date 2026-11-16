<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Jira;

use SensitiveParameter;

/**
 * A started Jira authorization: the URL the browser goes to, and for OAuth 1.0a
 * the request token Jira will carry back to the callback as `oauth_token`. The
 * connect action keeps that token in the session so the callback only accepts
 * an authorization this session started. Jira Cloud binds the flow through
 * `state` instead and has no request token.
 */
final readonly class JiraAuthorizationRequest
{
    public function __construct(
        public string $url,
        #[SensitiveParameter]
        public ?string $requestToken = null,
    ) {
    }
}

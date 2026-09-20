<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Extension;

use App\Security\Csp\CspNonceProvider;
use Twig\Attribute\AsTwigFunction;

/**
 * `csp_nonce()` for inline <script> blocks.
 *
 * The value is the same one ContentSecurityPolicySubscriber writes into the
 * header for this request, because both read it from CspNonceProvider.
 */
final readonly class CspTwigExtension
{
    public function __construct(private CspNonceProvider $nonceProvider)
    {
    }

    #[AsTwigFunction('csp_nonce')]
    public function cspNonce(): string
    {
        return $this->nonceProvider->getNonce();
    }
}

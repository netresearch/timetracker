<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\Csp;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function base64_encode;
use function is_string;
use function random_bytes;

/**
 * One Content-Security-Policy nonce per request, generated on first use.
 *
 * Both sides of the policy read it here: the `csp_nonce()` Twig function when a
 * template renders an inline <script>, and ContentSecurityPolicySubscriber when
 * it writes the header. Storing it on the Request rather than in a property is
 * what keeps the two in step and makes the value die with the request instead
 * of leaking into the next one on a long-running worker.
 *
 * Generating it lazily means a response that renders no template still carries
 * a nonce in its header. That costs 18 bytes of entropy and keeps the header
 * identical in shape for every response, which is one fewer thing to reason
 * about when reading a violation report.
 */
final readonly class CspNonceProvider
{
    public const string ATTRIBUTE = '_csp_nonce';

    public function __construct(private RequestStack $requestStack)
    {
    }

    /**
     * The base64 nonce, without the `nonce-` prefix the header uses.
     *
     * Returns an empty string when there is no request — a console command
     * rendering a template, for instance. An empty `nonce=""` matches no source
     * expression, which is the safe direction: the script is reported, never
     * silently allowed.
     */
    public function getNonce(): string
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return '';
        }

        $nonce = $request->attributes->get(self::ATTRIBUTE);
        if (!is_string($nonce) || '' === $nonce) {
            // 18 bytes encode to 24 base64 characters with no padding.
            $nonce = base64_encode(random_bytes(18));
            $request->attributes->set(self::ATTRIBUTE, $nonce);
        }

        return $nonce;
    }
}

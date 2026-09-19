<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\Csp;

use function implode;
use function is_int;
use function is_string;
use function parse_url;
use function sprintf;

use const PHP_URL_HOST;
use const PHP_URL_PORT;
use const PHP_URL_SCHEME;

/**
 * Builds the Content-Security-Policy header value.
 *
 * Nonce-based rather than 'unsafe-inline': the eight inline <script> blocks in
 * templates/ each carry `nonce="{{ csp_nonce() }}"`, so the policy can name the
 * nonce and refuse everything else. A policy with 'unsafe-inline' in script-src
 * would allow exactly the injection it exists to stop.
 *
 * frame-src is the one directive that cannot be a constant. The corporate
 * navigation in partials/header.html.twig loads APP_HEADER_URL into an iframe,
 * and that origin differs per deployment, so it is derived from the configured
 * URL. When the setting is empty no iframe is rendered and the directive stays
 * 'none'.
 */
final readonly class ContentSecurityPolicyBuilder
{
    public function __construct(private string $headerUrl)
    {
    }

    /**
     * @param string $nonce base64 nonce, without the `nonce-` prefix
     */
    public function build(string $nonce): string
    {
        $frameSrc = $this->frameSource();

        $directives = [
            // Everything not named below falls back to same-origin only.
            "default-src 'self'",
            // The nonce covers the inline bootstrap blocks; Vite's built assets
            // are same-origin files.
            sprintf("script-src 'self' 'nonce-%s'", $nonce),
            // 'unsafe-inline' is still needed for styles: the error pages carry
            // an inline <style> block and Vite injects one in development. It is
            // a far smaller exposure than script-src would be, and narrowing it
            // is what the report-only phase should tell us the cost of.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            sprintf('frame-src %s', $frameSrc),
            // Nothing may frame us. Enforced today by X-Frame-Options: DENY,
            // which this directive supersedes once the policy is enforcing —
            // frame-ancestors is ignored in a report-only policy.
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }

    /**
     * The scheme, host and port of APP_HEADER_URL, or 'none' when unset or
     * unparsable. A deployment without a corporate header frames nothing.
     */
    private function frameSource(): string
    {
        if ('' === $this->headerUrl) {
            return "'none'";
        }

        $scheme = parse_url($this->headerUrl, PHP_URL_SCHEME);
        $host = parse_url($this->headerUrl, PHP_URL_HOST);
        if (!is_string($scheme) || !is_string($host) || '' === $scheme || '' === $host) {
            return "'none'";
        }

        $port = parse_url($this->headerUrl, PHP_URL_PORT);

        return is_int($port)
            ? sprintf('%s://%s:%d', $scheme, $host, $port)
            : sprintf('%s://%s', $scheme, $host);
    }
}

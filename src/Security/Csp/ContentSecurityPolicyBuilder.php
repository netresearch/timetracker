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
 * Nonce-based rather than 'unsafe-inline': the nine inline <script> blocks in
 * templates/ each carry `nonce="{{ csp_nonce() }}"` — the eight executable ones
 * and the application/ld+json block, which is not subject to script-src but
 * costs nothing to nonce — so the policy can name the nonce and refuse
 * everything else. A policy with 'unsafe-inline' in script-src would allow
 * exactly the injection it exists to stop.
 *
 * frame-src is the one directive that cannot be a constant. The corporate
 * navigation in partials/header.html.twig loads APP_HEADER_URL into an iframe,
 * and that origin differs per deployment, so it is derived from the configured
 * URL. When the setting is empty no iframe is rendered and the directive stays
 * 'none'.
 */
final readonly class ContentSecurityPolicyBuilder
{
    /**
     * @param bool $allowInlineStyles keep 'unsafe-inline' in style-src, for
     *                                debug builds only: Vite injects a
     *                                <style> element it does not nonce while
     *                                serving from the dev server. A production
     *                                build links its stylesheet, so the
     *                                allowance is not needed there and is not
     *                                granted.
     */
    public function __construct(
        private string $headerUrl,
        private bool $allowInlineStyles = false,
    ) {
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
            // The error page's inline <style> carries the nonce like the
            // scripts do. 'unsafe-inline' is added only for a debug build,
            // where Vite's dev server injects a <style> element of its own
            // that nothing can nonce.
            sprintf(
                "style-src 'self' 'nonce-%s'%s",
                $nonce,
                $this->allowInlineStyles ? " 'unsafe-inline'" : '',
            ),
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
     * The origin of APP_HEADER_URL, or 'none' when unset, unparsable or not
     * https.
     *
     * https only, deliberately. Widening frame-src to a plaintext origin would
     * write an active-content downgrade into the policy — and on an https
     * deployment the browser blocks that iframe as mixed content anyway, so
     * the directive would grant something that cannot load. A corporate
     * navigation served over http is a misconfiguration to surface, not one to
     * accommodate: the iframe simply does not load and frame-src says why.
     */
    private function frameSource(): string
    {
        if ('' === $this->headerUrl) {
            return "'none'";
        }

        $scheme = parse_url($this->headerUrl, PHP_URL_SCHEME);
        $host = parse_url($this->headerUrl, PHP_URL_HOST);
        if ('https' !== $scheme || !is_string($host) || '' === $host) {
            return "'none'";
        }

        $port = parse_url($this->headerUrl, PHP_URL_PORT);

        return is_int($port)
            ? sprintf('https://%s:%d', $host, $port)
            : sprintf('https://%s', $host);
    }
}

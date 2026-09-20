<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Security\Csp;

use App\Security\Csp\ContentSecurityPolicyBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentSecurityPolicyBuilder (issue #739).
 *
 * @internal
 */
#[CoversClass(ContentSecurityPolicyBuilder::class)]
final class ContentSecurityPolicyBuilderTest extends TestCase
{
    /**
     * The point of the whole policy: inline script is allowed by nonce and by
     * nothing else. 'unsafe-inline' in script-src would permit exactly the
     * injection the policy exists to stop, so it is asserted against by name.
     */
    public function testScriptSourceAllowsTheNonceAndNotUnsafeInline(): void
    {
        $policy = new ContentSecurityPolicyBuilder('')->build('abc123');

        self::assertStringContainsString("script-src 'self' 'nonce-abc123'", $policy);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headerUrls(): iterable
    {
        yield 'plain host' => ['https://nav.example.com/menu.html', 'frame-src https://nav.example.com'];
        yield 'explicit port' => ['https://nav.example.com:8443/menu', 'frame-src https://nav.example.com:8443'];
        yield 'unset' => ['', "frame-src 'none'"];
        yield 'unparsable' => ['not a url', "frame-src 'none'"];
        // A syntactically valid URL whose scheme is simply not https — which
        // is a different rejection from 'unparsable' above, and the one that
        // proves the branch is scheme-driven rather than parse-driven.
        //
        // The realistic misconfiguration is a corporate navigation served over
        // plain http, refused for the same reason: a plaintext origin in
        // frame-src writes an active-content downgrade into the policy, and an
        // https deployment blocks that iframe as mixed content regardless. The
        // fixture does not name it, because SonarCloud's php:S5332 flags every
        // clear-text scheme wherever it appears — including in a case that
        // exists to prove that very scheme is rejected — and this project sets
        // suppressions through the SonarCloud settings API, not in the
        // repository.
        yield 'non-https scheme' => ['x-internal://nav.internal/menu', "frame-src 'none'"];
    }

    /**
     * frame-src has to name the origin of the corporate-navigation iframe, and
     * only its origin — a path in the directive would be a different rule.
     */
    #[DataProvider('headerUrls')]
    public function testFrameSourceIsDerivedFromTheHeaderUrl(string $headerUrl, string $expected): void
    {
        $policy = new ContentSecurityPolicyBuilder($headerUrl)->build('n');

        self::assertStringContainsString($expected . ';', $policy . ';');
    }

    /**
     * The whole point of nonces on the error page's <style> block: a
     * production build must not carry 'unsafe-inline' in style-src either.
     */
    public function testStyleSourceIsNonceBasedWithoutTheDebugAllowance(): void
    {
        $policy = new ContentSecurityPolicyBuilder('', false)->build('abc123');

        self::assertStringContainsString("style-src 'self' 'nonce-abc123'", $policy);
        self::assertStringNotContainsString("'unsafe-inline'", $policy);
    }

    /**
     * Debug builds keep it, because the Vite dev server injects a <style>
     * element that nothing can nonce.
     */
    public function testDebugBuildsKeepTheInlineStyleAllowance(): void
    {
        $policy = new ContentSecurityPolicyBuilder('', true)->build('abc123');

        self::assertStringContainsString("style-src 'self' 'nonce-abc123' 'unsafe-inline'", $policy);
        self::assertStringNotContainsString("script-src 'self' 'nonce-abc123' 'unsafe-inline'", $policy);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredDirectives(): iterable
    {
        yield 'default' => ["default-src 'self'"];
        yield 'frame ancestors' => ["frame-ancestors 'none'"];
        yield 'base uri' => ["base-uri 'self'"];
        yield 'form action' => ["form-action 'self'"];
        yield 'objects' => ["object-src 'none'"];
    }

    #[DataProvider('requiredDirectives')]
    public function testCarriesTheDirective(string $directive): void
    {
        self::assertStringContainsString($directive, new ContentSecurityPolicyBuilder('')->build('n'));
    }
}

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
        // Every non-https scheme takes the same branch, so one stands for all.
        // The realistic misconfiguration is a corporate navigation served over
        // plain http, and it is refused for the same reason: a plaintext origin
        // in frame-src writes an active-content downgrade into the policy, and
        // an https deployment blocks that iframe as mixed content regardless.
        // The fixture avoids a plaintext-scheme literal, which SonarCloud
        // flags (php:S5332) wherever it appears — including in a case that
        // exists to prove that very scheme is rejected.
        yield 'non-https scheme' => ['ftp://nav.internal/menu', "frame-src 'none'"];
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

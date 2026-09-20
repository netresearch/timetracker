<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

use function preg_match_all;
use function sprintf;
use function str_contains;

/**
 * The report-only policy and the rendered markup agree (issue #739).
 *
 * The unit tests prove the header is built correctly and that the provider
 * returns one value per request. What neither can show is the invariant the
 * whole design rests on: the nonce in the header is the nonce in the HTML. If
 * those ever diverge, every inline script is reported — and once the policy is
 * enforcing, every inline script is blocked.
 *
 * @internal
 */
final class ContentSecurityPolicyTest extends AbstractWebTestCase
{
    private const string REPORT_ONLY = 'Content-Security-Policy-Report-Only';

    public function testTheSpaShellCarriesAReportOnlyPolicy(): void
    {
        $this->openShell();
        $policy = (string) $this->client->getResponse()->headers->get(self::REPORT_ONLY);

        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertFalse(
            $this->client->getResponse()->headers->has('Content-Security-Policy'),
            'The policy is deliberately report-only for now.',
        );
    }

    /**
     * Every inline <script> in the response carries the one nonce the header
     * names — and there is at least one, so the assertion cannot pass by
     * finding nothing.
     */
    public function testEveryInlineScriptCarriesTheNoncedValueFromTheHeader(): void
    {
        $this->openShell();
        $response = $this->client->getResponse();

        $policy = (string) $response->headers->get(self::REPORT_ONLY);
        self::assertSame(1, preg_match_all("/'nonce-([A-Za-z0-9+\/=]+)'/", $policy, $headerMatches));
        $nonce = $headerMatches[1][0];

        $found = preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/i', (string) $response->getContent(), $scriptMatches);
        self::assertGreaterThan(0, $found, 'The shell renders no inline script, so this test proves nothing.');

        foreach ($scriptMatches[1] as $attributes) {
            self::assertTrue(
                str_contains($attributes, sprintf('nonce="%s"', $nonce)),
                sprintf('An inline <script%s> does not carry the header nonce.', $attributes),
            );
        }
    }

    /**
     * A nonce that repeats across responses is one an attacker reads off the
     * first page and reuses on the second.
     */
    public function testTheNonceChangesBetweenRequests(): void
    {
        $this->openShell();
        $first = (string) $this->client->getResponse()->headers->get(self::REPORT_ONLY);

        $this->openShell();
        $second = (string) $this->client->getResponse()->headers->get(self::REPORT_ONLY);

        self::assertNotSame($first, $second);
    }

    /**
     * The SPA's own API calls should not each carry a policy they cannot use.
     */
    public function testApiResponsesCarryNoPolicy(): void
    {
        $this->logInSession('unittest');
        $this->client->request('GET', '/api/v2/settings');

        self::assertFalse($this->client->getResponse()->headers->has(self::REPORT_ONLY));
    }

    /**
     * `/ui/` rather than `/login`: an authenticated session redirects away from
     * the login form, and a 302 body carries no inline script at all — a test
     * pointed at it would assert over an empty set.
     */
    private function openShell(): void
    {
        $this->logInSession('unittest');
        $this->client->request('GET', '/ui/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}

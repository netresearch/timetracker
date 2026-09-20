<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AbstractWebTestCase;

/**
 * The security headers reach a real response (issue #739).
 *
 * The unit test next to SecurityHeadersSubscriber proves the subscriber sets
 * them; this proves the subscriber is wired into the kernel and that nothing
 * downstream strips them. Both halves are needed: an unregistered subscriber
 * passes the unit test and ships a bare response.
 *
 * @internal
 */
final class SecurityHeadersTest extends AbstractWebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expectedHeaders(): iterable
    {
        yield 'framing' => ['X-Frame-Options', 'DENY'];
        yield 'mime sniffing' => ['X-Content-Type-Options', 'nosniff'];
        yield 'referrer' => ['Referrer-Policy', 'same-origin'];
    }

    /**
     * The login route is the one an unauthenticated visitor reaches, so it is
     * the response an attacker sees first. Note that an authenticated test
     * session is redirected away from it, so what is asserted here is the 302 —
     * which is exactly the kind of response a header can be forgotten on.
     */
    #[DataProvider('expectedHeaders')]
    public function testLoginRouteCarriesTheHeader(string $name, string $value): void
    {
        $this->client->request('GET', '/login');

        self::assertSame($value, $this->client->getResponse()->headers->get($name));
    }

    /**
     * The rendered application shell, which is the page a user actually sits
     * on and the one an injected script would run in.
     */
    #[DataProvider('expectedHeaders')]
    public function testTheSpaShellCarriesTheHeader(string $name, string $value): void
    {
        $this->logInSession('unittest');
        $this->client->request('GET', '/ui/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame($value, $this->client->getResponse()->headers->get($name));
    }

    /**
     * nosniff matters most where the body is data: a browser that guesses a
     * content type is how a JSON response becomes a script.
     */
    #[DataProvider('expectedHeaders')]
    public function testApiResponseCarriesTheHeader(string $name, string $value): void
    {
        $this->logInSession('unittest');
        $this->client->request('GET', '/api/v2/settings');

        self::assertSame($value, $this->client->getResponse()->headers->get($name));
    }
}

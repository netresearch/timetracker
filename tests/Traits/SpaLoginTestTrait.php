<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Traits;

use OTPHP\TOTP;
use Symfony\Component\HttpFoundation\Request;

use function preg_match;

/**
 * The SPA's side of the login and 2FA-enrolment flows, for functional tests
 * against the real firewall (ADR-018): the XHR password step with the login
 * page's CSRF token, JSON requests, and TOTP enrolment through the settings
 * endpoints.
 */
trait SpaLoginTestTrait
{
    /**
     * POST the password step the way the SPA does (fetch/XHR): render the login
     * page first to obtain the 'authenticate' CSRF token (and its double-submit
     * cookie), then submit the credentials with it.
     */
    private function postLogin(string $password, string $username = 'unittest', bool $rememberMe = false): void
    {
        $this->client->request(Request::METHOD_GET, '/login');
        $html = $this->client->getResponse()->getContent();
        self::assertIsString($html);
        self::assertSame(1, preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $matches), 'login page must render the CSRF token');

        $parameters = [
            '_username' => $username,
            '_password' => $password,
            '_csrf_token' => $matches[1],
        ];
        if ($rememberMe) {
            $parameters['_remember_me'] = 'on';
        }

        $this->requestJson(Request::METHOD_POST, '/login', $parameters);
    }

    /**
     * @param array<string, string> $body
     */
    private function requestJson(string $method, string $uri, array $body = []): void
    {
        $this->client->request($method, $uri, $body, [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
    }

    /**
     * Enrol TOTP for the logged-in user through the settings endpoints.
     *
     * @return array{secret: non-empty-string, backupCodes: list<string>}
     */
    private function confirmTotpThroughSettings(): array
    {
        $this->client->request(Request::METHOD_POST, '/settings/2fa/totp/start', [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $secret = $this->getJsonResponse($this->client->getResponse())['secret'] ?? null;
        self::assertIsString($secret);
        self::assertNotSame('', $secret);

        $this->client->request(Request::METHOD_POST, '/settings/2fa/totp/confirm', ['code' => TOTP::createFromSecret($secret)->now()], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $codes = $this->getJsonResponse($this->client->getResponse())['backupCodes'] ?? null;
        self::assertIsArray($codes);
        self::assertNotCount(0, $codes);
        /** @var list<string> $codes */

        return ['secret' => $secret, 'backupCodes' => $codes];
    }
}

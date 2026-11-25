<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Override;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\RemovesEnvironmentVariablesTrait;

/**
 * The application runs without the variables compose.yml does not forward.
 *
 * @internal
 *
 * @coversNothing
 */
final class MissingEnvironmentTest extends AbstractWebTestCase
{
    use RemovesEnvironmentVariablesTrait;

    #[Override]
    protected function setUp(): void
    {
        $this->removeEnvironmentVariables(['REQUIRE_TWO_FACTOR', 'WEBAUTHN_RP_ID', 'WEBAUTHN_ALLOWED_ORIGINS', 'MCP_ALLOWED_HOSTS']);
        parent::setUp();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->restoreEnvironmentVariables();
        parent::tearDown();
    }

    public function testAnswersRequestsWithoutMandatoryTwoFactor(): void
    {
        $this->client->request(Request::METHOD_GET, '/status/check');

        $this->assertStatusCode(200);
        self::assertFalse(self::getContainer()->getParameter('app_require_two_factor'));
    }

    public function testRelyingPartyIsLocalhost(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/settings/security/passkeys/options',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        $this->assertStatusCode(200);
        $options = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($options['rp'] ?? null);
        self::assertSame('localhost', $options['rp']['id'] ?? null);
    }

    public function testPublishesNoOrigin(): void
    {
        $this->client->request(Request::METHOD_GET, '/.well-known/webauthn');

        $this->assertStatusCode(200);
        self::assertSame('{"origins":[""]}', $this->client->getResponse()->getContent());
    }
}

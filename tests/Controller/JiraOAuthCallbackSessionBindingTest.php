<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\DTO\Jira\JiraAuthorizationRequest;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

/**
 * The OAuth 1.0a callback accepts only the request token this session's
 * connect action issued. Otherwise anybody could send a user a callback link
 * carrying a token they authorized themselves, and the user's account would
 * book worklogs with that person's Jira access token.
 *
 * @internal
 *
 * @coversNothing
 */
final class JiraOAuthCallbackSessionBindingTest extends AbstractWebTestCase
{
    /** @var list<array{string, string}> request token and verifier of each exchange */
    private array $exchanges = [];

    protected function setUp(): void
    {
        parent::setUp();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement("UPDATE ticket_systems SET type = 'JIRA', book_time = 1 WHERE id = 1");

        $api = self::createStub(JiraOAuthApiService::class);
        $api->method('createAuthorizationRequest')->willReturn(
            new JiraAuthorizationRequest('https://jira.example.com/plugins/servlet/oauth/authorize?oauth_token=issued-token', 'issued-token'),
        );
        $api->method('fetchOAuthAccessToken')->willReturnCallback(function (string $requestToken, string $verifier): void {
            $this->exchanges[] = [$requestToken, $verifier];
        });
        $factory = self::createStub(JiraOAuthApiFactory::class);
        $factory->method('create')->willReturn($api);
        self::getContainer()->set(JiraOAuthApiFactory::class, $factory);
    }

    public function testTheCallbackExchangesTheRequestTokenThisSessionStarted(): void
    {
        $this->connect();

        $this->returnFromJira('issued-token', 'verifier');

        $this->assertStatusCode(302);
        self::assertSame([['issued-token', 'verifier']], $this->exchanges);
    }

    public function testTheCallbackRefusesARequestTokenThisSessionDidNotStart(): void
    {
        $this->connect();

        $this->returnFromJira('someone-elses-token', 'verifier');

        $this->assertStatusCode(400);
        self::assertSame([], $this->exchanges);

        // The refusal leaves the pending authorization in place.
        $this->returnFromJira('issued-token', 'verifier');
        $this->assertStatusCode(302);
        self::assertSame([['issued-token', 'verifier']], $this->exchanges);
    }

    public function testTheCallbackRefusesWhenNoAuthorizationWasStarted(): void
    {
        $this->returnFromJira('issued-token', 'denied');

        $this->assertStatusCode(400);
        self::assertSame([], $this->exchanges);
    }

    public function testTheCallbackRefusesTheSameRequestTokenTwice(): void
    {
        $this->connect();
        $this->returnFromJira('issued-token', 'verifier');

        $this->returnFromJira('issued-token', 'verifier');

        $this->assertStatusCode(400);
        self::assertSame([['issued-token', 'verifier']], $this->exchanges);
    }

    private function connect(): void
    {
        $this->client->request(Request::METHOD_GET, '/jira/connect/1');
        $this->assertStatusCode(302);
    }

    private function returnFromJira(string $requestToken, string $verifier): void
    {
        $this->client->request(Request::METHOD_GET, '/jiraoauthcallback', [
            'tsid' => 1,
            'oauth_token' => $requestToken,
            'oauth_verifier' => $verifier,
        ]);
    }
}

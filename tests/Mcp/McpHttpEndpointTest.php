<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Mcp;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ApiToken\ApiTokenService;
use Tests\AbstractWebTestCase;

use const JSON_THROW_ON_ERROR;

/**
 * Functional tests for the /mcp HTTP endpoint (ADR-021 Phase 5), driving the full
 * stack — the Bearer-PAT firewall, our McpEndpointController, and the SDK's
 * Streamable-HTTP transport middleware (incl. the DNS-rebinding Host guard the
 * direct tool tests bypass). Regression cover for the localhost-only default that
 * 403'd a real domain until McpEndpointController allowlisted the host.
 */
final class McpHttpEndpointTest extends AbstractWebTestCase
{
    private const string INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"phpunit","version":"1"}}}';

    public function testInitializeSucceedsForAnAllowedHost(): void
    {
        // MCP_ALLOWED_HOSTS in the test env includes localhost (the test client's
        // host), so the DNS-rebinding guard must let the handshake through.
        $this->client->request('POST', '/mcp', server: $this->mcpServer('localhost'), content: self::INITIALIZE);

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(403, $status, 'the request was rejected before reaching the MCP transport');
        self::assertSame(200, $status);
    }

    public function testDisallowedHostIsForbidden(): void
    {
        // A host not in MCP_ALLOWED_HOSTS must still be rejected — the guard stays active.
        $this->client->request('POST', '/mcp', server: $this->mcpServer('evil.example.com'), content: self::INITIALIZE);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testToolsListExposesTheRegisteredTools(): void
    {
        // The SDK loads its element registry lazily since v0.7.0, so the tools only
        // materialise on the first registry read — tools/list, not the handshake.
        // The direct tool tests fetch the services from the container and would not
        // notice an empty registry here.
        $server = $this->mcpServer('localhost');
        $this->client->request('POST', '/mcp', server: $server, content: self::INITIALIZE);
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotNull($sessionId, 'the handshake did not open a session');

        $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        $this->client->request('POST', '/mcp', server: $server, content: '{"jsonrpc":"2.0","id":2,"method":"tools/list"}');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        /** @var array{result: array{tools: list<array{name: string}>}} $payload */
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($payload['result']['tools'], 'name');
        self::assertContains('get_day', $names);
        self::assertContains('log_time', $names);
    }

    public function testBogusTokenIsUnauthorized(): void
    {
        // A tt_pat_ Bearer is claimed by the stateless api firewall; an invalid one
        // is rejected before the request reaches the MCP transport.
        $server = $this->mcpServer('localhost');
        $server['HTTP_AUTHORIZATION'] = 'Bearer tt_pat_bogus';
        $this->client->request('POST', '/mcp', server: $server, content: self::INITIALIZE);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    private function mcpServer(string $host): array
    {
        return [
            'HTTP_HOST' => $host,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->readToken(),
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ];
    }

    private function readToken(): string
    {
        $container = self::getContainer();
        $user = $container->get(UserRepository::class)->find(1);
        self::assertInstanceOf(User::class, $user);

        [, $plaintext] = $container->get(ApiTokenService::class)->create($user, 'mcp-http-test', ['entries:read'], null);

        return $plaintext;
    }
}

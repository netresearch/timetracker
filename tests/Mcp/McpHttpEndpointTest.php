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

use function sprintf;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

/**
 * Functional tests for the /mcp HTTP endpoint (ADR-021 Phase 5), driving the full
 * stack — the Bearer-PAT firewall, the bundle's McpController, and the SDK's
 * Streamable-HTTP transport middleware (incl. the DNS-rebinding Host guard the
 * direct tool tests bypass). Regression cover for the localhost-only default that
 * 403'd a real domain until mcp.http.allowed_hosts allowlisted the host.
 */
final class McpHttpEndpointTest extends AbstractWebTestCase
{
    /** The stateless revision the SDK serves next to the handshake era since mcp/sdk 0.8. */
    private const string MODERN_REVISION = '2026-07-28';

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

    public function testMissingTokenIsNotServed(): void
    {
        // Without a Bearer PAT the api firewall does not claim the request; the
        // session firewall must not let an anonymous caller reach the transport.
        $server = $this->mcpServer('localhost');
        unset($server['HTTP_AUTHORIZATION']);
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/mcp', server: $server, content: self::INITIALIZE);

        // The session firewall's form_login entry point sends the caller to log in.
        self::assertResponseRedirects('/login');
    }

    public function testHandshakeToolCallIsRefusedWithoutTheToolScope(): void
    {
        $result = $this->handshakeToolCall(['projects:read'], 'get_day');

        self::assertTrue($result['isError'] ?? false, 'a token without entries:read must not run get_day');
        self::assertStringContainsString('missing the required scope "entries:read"', $result['content'][0]['text'] ?? '');
    }

    public function testHandshakeToolCallRunsWithTheToolScope(): void
    {
        $result = $this->handshakeToolCall(['entries:read'], 'get_day');

        self::assertFalse($result['isError'] ?? false, 'a token with entries:read must run get_day');
    }

    public function testModernEraRequestWithoutTokenIsNotServed(): void
    {
        // The 2026-07-28 revision is stateless and has no handshake, so the
        // firewall is the only gate in front of a tools/call on that leg.
        $server = $this->modernServer('get_day', $this->readToken());
        unset($server['HTTP_AUTHORIZATION']);
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/mcp', server: $server, content: $this->modernToolCall('get_day'));

        // The session firewall's form_login entry point sends the caller to log in.
        self::assertResponseRedirects('/login');
    }

    public function testModernEraToolCallWithOnlyASessionCookieIsRefused(): void
    {
        // setUp() logged a user in through the session firewall. That cookie may
        // reach the transport, but a tool must still demand a PAT (ScopeGuard).
        $server = $this->modernServer('get_day', $this->readToken());
        unset($server['HTTP_AUTHORIZATION']);
        $this->client->request('POST', '/mcp', server: $server, content: $this->modernToolCall('get_day'));
        $result = $this->toolResult();

        self::assertTrue($result['isError'] ?? false, 'a session cookie without a PAT must not run get_day');
        self::assertStringContainsString('requires a personal access token', $result['content'][0]['text'] ?? '');
    }

    public function testModernEraToolCallIsRefusedWithoutTheToolScope(): void
    {
        $this->client->request('POST', '/mcp', server: $this->modernServer('get_day', $this->readToken(['projects:read'])), content: $this->modernToolCall('get_day'));
        $result = $this->toolResult();

        self::assertTrue($result['isError'] ?? false, 'a token without entries:read must not run get_day');
        self::assertStringContainsString('missing the required scope "entries:read"', $result['content'][0]['text'] ?? '');
    }

    public function testModernEraToolCallRunsWithTheToolScope(): void
    {
        $this->client->request('POST', '/mcp', server: $this->modernServer('get_day', $this->readToken(['entries:read'])), content: $this->modernToolCall('get_day'));
        $result = $this->toolResult();

        self::assertFalse($result['isError'] ?? false, 'a token with entries:read must run get_day');
    }

    /**
     * Opens a handshake-era session with a token holding $scopes and calls $tool.
     *
     * @param list<string> $scopes
     *
     * @return array{isError?: bool, content?: list<array{text?: string}>}
     */
    private function handshakeToolCall(array $scopes, string $tool): array
    {
        $server = $this->mcpServer('localhost', $this->readToken($scopes));
        $this->client->request('POST', '/mcp', server: $server, content: self::INITIALIZE);
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotNull($sessionId, 'the handshake did not open a session');

        $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        $this->client->request('POST', '/mcp', server: $server, content: '{"jsonrpc":"2.0","method":"notifications/initialized"}');
        $this->client->request('POST', '/mcp', server: $server, content: sprintf('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"%s","arguments":{}}}', $tool));

        return $this->toolResult();
    }

    /**
     * The `result` of the last JSON-RPC response, read from a JSON body or from
     * the `data:` line of a single-event SSE stream.
     *
     * @return array{isError?: bool, content?: list<array{text?: string}>}
     */
    private function toolResult(): array
    {
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $body = (string) $response->getContent();
        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/event-stream')) {
            self::assertSame(1, preg_match('/^data: (.+)$/m', $body, $match), $body);
            $body = $match[1];
        }

        /** @var array{result?: array{isError?: bool, content?: list<array{text?: string}>}} $payload */
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('result', $payload, $body);

        return $payload['result'];
    }

    private function modernToolCall(string $tool): string
    {
        return sprintf(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"%s","arguments":{},"_meta":{"io.modelcontextprotocol/protocolVersion":"%s","io.modelcontextprotocol/clientCapabilities":{},"io.modelcontextprotocol/clientInfo":{"name":"phpunit","version":"1"}}}}',
            $tool,
            self::MODERN_REVISION,
        );
    }

    /**
     * @return array<string, string>
     */
    private function modernServer(string $tool, string $token): array
    {
        return [
            'HTTP_HOST' => 'localhost',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MCP_PROTOCOL_VERSION' => self::MODERN_REVISION,
            'HTTP_MCP_METHOD' => 'tools/call',
            'HTTP_MCP_NAME' => $tool,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function mcpServer(string $host, ?string $token = null): array
    {
        return [
            'HTTP_HOST' => $host,
            'HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->readToken()),
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ];
    }

    /**
     * @param list<string> $scopes
     */
    private function readToken(array $scopes = ['entries:read']): string
    {
        $container = self::getContainer();
        $user = $container->get(UserRepository::class)->find(1);
        self::assertInstanceOf(User::class, $user);

        [, $plaintext] = $container->get(ApiTokenService::class)->create($user, 'mcp-http-test', $scopes, null);

        return $plaintext;
    }
}

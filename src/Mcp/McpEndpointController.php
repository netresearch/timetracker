<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp;

use App\Security\ApiToken\SelfEnforcesScope;
use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function strtolower;

/**
 * MCP HTTP endpoint (ADR-021 Phase 5), replacing the bundle's McpController
 * (which is final and hardcodes the transport's default middleware).
 *
 * The SDK's StreamableHttpTransport defaults its DnsRebindingProtectionMiddleware
 * to `allowedHosts = [localhost, 127.0.0.1, [::1]]`, so behind a real domain the
 * `Host` header is rejected with 403 "Invalid Host header". We rebuild the same
 * secure stack (CORS + DNS-rebinding + protocol-version) but with the deployment's
 * host(s) allowlisted (see `app.mcp_allowed_hosts` / MCP_ALLOWED_HOSTS).
 */
final readonly class McpEndpointController implements SelfEnforcesScope
{
    /**
     * @param list<string> $allowedHosts hostnames (no port) permitted by the
     *                                   DNS-rebinding check; IPv6 must be bracketed
     */
    public function __construct(
        private Server $server,
        private HttpMessageFactoryInterface $httpMessageFactory,
        private HttpFoundationFactoryInterface $httpFoundationFactory,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private array $allowedHosts,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $transport = new StreamableHttpTransport(
            $this->httpMessageFactory->createRequest($request),
            $this->responseFactory,
            $this->streamFactory,
            logger: $this->logger,
            middleware: [
                new CorsMiddleware(),
                new DnsRebindingProtectionMiddleware(allowedHosts: $this->allowedHosts),
                new ProtocolVersionMiddleware(),
            ],
        );

        $psrResponse = $this->server->run($transport);
        $streamed = 'text/event-stream' === strtolower($psrResponse->getHeaderLine('Content-Type'));

        return $this->httpFoundationFactory->createResponse($psrResponse, $streamed);
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\AbstractWebTestCase;

/**
 * The well-known / agent-discovery endpoints are public (no session) and
 * standards-shaped. See docs/agent-readiness.md.
 *
 * @internal
 *
 * @coversNothing
 */
final class WellKnownControllerTest extends AbstractWebTestCase
{
    public function testSecurityTxtIsPublicPlainTextWithRequiredFields(): void
    {
        $this->client->request(Request::METHOD_GET, '/.well-known/security.txt');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('Contact:', $body);
        self::assertStringContainsString('Expires:', $body);
        self::assertStringContainsString('Canonical:', $body);
    }

    public function testChangePasswordRedirectsToSettings(): void
    {
        $this->client->request(Request::METHOD_GET, '/.well-known/change-password');

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/ui/settings', $response->headers->get('Location'));
    }

    public function testApiCatalogIsALinkSetPointingAtTheOpenApi(): void
    {
        $this->client->request(Request::METHOD_GET, '/.well-known/api-catalog');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/linkset+json', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        $data = json_decode($body, true);
        self::assertIsArray($data);
        self::assertArrayHasKey('linkset', $data);
        self::assertStringContainsString('/api.yml', $body);
    }

    public function testLlmsTxtIsPublicMarkdown(): void
    {
        $this->client->request(Request::METHOD_GET, '/llms.txt');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('# TimeTracker', (string) $response->getContent());
    }

    public function testDiscoveryLinkHeaderAdvertisesTheApi(): void
    {
        // The subscriber adds two Web Linking headers to every main response.
        $this->client->request(Request::METHOD_GET, '/.well-known/security.txt');

        $links = implode(', ', $this->client->getResponse()->headers->all('Link'));
        self::assertStringContainsString('rel="service-desc"', $links);
        self::assertStringContainsString('rel="api-catalog"', $links);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class StatusControllerTest extends AbstractWebTestCase
{
    public function testCheckAction(): void
    {
        $expectedJson = [
            'loginStatus' => true,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/check');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testPageAction(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/page');
        $this->assertStatusCode(200);

        // Check that the response contains valid HTML
        $response = $this->client->getResponse();
        $content = $response->getContent();

        // Just verify we got HTML with expected content
        self::assertStringContainsString('<!DOCTYPE HTML>', (string) $content);
        self::assertStringContainsString('<html>', (string) $content);
        self::assertStringContainsString('Login-Status', (string) $content);
        self::assertStringContainsString('class="status_active"', (string) $content);
    }

    public function testPageActionWithLoggedInUserReturnsActiveStatus(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/page');
        $this->assertStatusCode(200);
        self::assertStringContainsString('class="status_active"', (string) $this->client->getResponse()->getContent());
    }

    public function testPageActionWithLoggedOutUserReturnsInactiveStatus(): void
    {
        $this->ensureKernelShutdown();
        $this->client = self::createClient();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/page');
        $this->assertStatusCode(200);
        self::assertStringContainsString('class="status_inactive"', (string) $this->client->getResponse()->getContent());
    }

    public function testCheckActionWithLoggedInUserReturnsActiveStatus(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/check');
        $this->assertStatusCode(200);
        $this->assertJsonStructure([
            'loginStatus' => true,
        ]);
    }

    public function testCheckActionWithLoggedOutUserReturnsInactiveStatus(): void
    {
        $this->ensureKernelShutdown();
        $this->client = self::createClient();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/check');
        $this->assertStatusCode(200);
        $this->assertJsonStructure([
            'loginStatus' => false,
        ]);
    }
}

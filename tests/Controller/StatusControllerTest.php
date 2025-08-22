<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class StatusControllerTest extends AbstractWebTestCase
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
        $this->assertStringContainsString('<!DOCTYPE HTML>', $content);
        $this->assertStringContainsString('<html>', $content);
        $this->assertStringContainsString('Login-Status', $content);
        $this->assertStringContainsString('class="status_active"', $content);
    }

    public function testPageActionWithLoggedInUserReturnsActiveStatus(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/page');
        $this->assertStatusCode(200);
        $this->assertStringContainsString('class="status_active"', $this->client->getResponse()->getContent());
    }

    public function testPageActionWithLoggedOutUserReturnsInactiveStatus(): void
    {
        $this->ensureKernelShutdown();
        $this->client = static::createClient();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/page');
        $this->assertStatusCode(200);
        $this->assertStringContainsString('class="status_inactive"', $this->client->getResponse()->getContent());
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
        $this->client = static::createClient();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/check');
        $this->assertStatusCode(200);
        $this->assertJsonStructure([
            'loginStatus' => false,
        ]);
    }


}

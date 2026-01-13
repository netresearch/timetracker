<?php

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

use function assert;
use function is_array;

/**
 * @internal
 *
 * @coversNothing
 */
final class ApiSmokeTest extends AbstractWebTestCase
{
    public function testGetActivities(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getActivities');
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        if ([] !== $data) {
            assert(is_array($data[0]));
            self::assertArrayHasKey('activity', $data[0]);
        }
    }

    public function testGetAllCustomers(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllCustomers');
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        if ([] !== $data) {
            assert(is_array($data[0]));
            self::assertArrayHasKey('customer', $data[0]);
        }
    }

    public function testGetAllProjects(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllProjects');
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        if ([] !== $data) {
            assert(is_array($data[0]));
            self::assertArrayHasKey('project', $data[0]);
        }
    }

    public function testGetAllTeams(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllTeams');
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        if ([] !== $data) {
            assert(is_array($data[0]));
            self::assertArrayHasKey('team', $data[0]);
        }
    }

    public function testGetAllUsers(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllUsers');
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        if ([] !== $data) {
            assert(is_array($data[0]));
            self::assertArrayHasKey('user', $data[0]);
        }
    }

    public function testGetContracts(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getContracts');
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        if ([] !== $data) {
            assert(is_array($data[0]));
            self::assertArrayHasKey('contract', $data[0]);
        }
    }

    public function testGetCustomers(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getCustomers');
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
    }

    public function testGetData(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getData');
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
    }

    public function testGetDataDays(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getData/days/3');
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
    }

    public function testGetTicketSystems(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getTicketSystems');
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
    }

    public function testGetTimeSummary(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getTimeSummary');
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('today', $data);
        self::assertArrayHasKey('week', $data);
        self::assertArrayHasKey('month', $data);
    }

    public function testGetTicketTimeSummary(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getTicketTimeSummary/TIM-1');
        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [200, 404], 'Status not in expected set');
    }

    public function testInterpretationEndpoints(): void
    {
        $paths = [
            '/interpretation/activity',
            '/interpretation/customer',
            '/interpretation/project',
            '/interpretation/ticket',
            '/interpretation/user',
            '/interpretation/time',
            '/interpretation/entries',
        ];
        foreach ($paths as $path) {
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $path);
            $status = $this->client->getResponse()->getStatusCode();
            self::assertContains($status, [200, 406], 'Status not in expected set');
        }
    }

    public function testStatusCheckAndPage(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/check');
        $this->assertStatusCode(200);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/status/page');
        $this->assertStatusCode(200);
    }
}

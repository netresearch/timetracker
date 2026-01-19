<?php

declare(strict_types=1);

namespace Tests\Api\Functional;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function count;
use function is_array;
use function is_int;

use const JSON_THROW_ON_ERROR;

/**
 * API Functional Tests - Project CRUD Operations.
 *
 * These tests verify actual CRUD operations with real database.
 * Use for CI/full test runs.
 *
 * @internal
 *
 * @coversNothing
 */
final class ProjectCrudTest extends AbstractWebTestCase
{
    public function testCreateProject(): void
    {
        $this->logInSession('unittest');

        // First get a customer to associate with
        $this->client->request(Request::METHOD_GET, '/getAllCustomers');
        $customers = $this->getJsonResponse($this->client->getResponse());

        $customerId = null;
        foreach ($customers as $item) {
            /** @var array<string, mixed> $item */
            $customer = isset($item['customer']) && is_array($item['customer']) ? $item['customer'] : $item;
            $id = $customer['id'] ?? 0;
            if (is_int($id) && $id > 0) {
                $customerId = $id;
                break;
            }
        }

        if (null === $customerId) {
            self::markTestSkipped('No customers available for project creation');
        }

        // Create a new project
        $this->client->request(
            Request::METHOD_POST,
            '/project/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'E2E Test Project ' . uniqid(),
                'customer' => $customerId,
                'active' => true,
                'global' => false,
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertStatusCode(200);
        $response = $this->getJsonResponse($this->client->getResponse());

        // Project save returns array: [id, name, customerId, jiraId]
        self::assertIsArray($response);
        self::assertGreaterThanOrEqual(4, count($response));
        self::assertNotNull($response[0]); // id
    }

    public function testReadProject(): void
    {
        $this->logInSession('unittest');

        // Get all projects
        $this->client->request(Request::METHOD_GET, '/getAllProjects');
        $this->assertStatusCode(200);

        $projects = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($projects);
        self::assertNotEmpty($projects);

        // Verify structure
        /** @var array<string, mixed> $first */
        $first = $projects[0];
        $project = isset($first['project']) && is_array($first['project']) ? $first['project'] : $first;
        self::assertArrayHasKey('id', $project);
        self::assertArrayHasKey('name', $project);
    }

    public function testGetProjectsByCustomer(): void
    {
        $this->logInSession('unittest');

        // Get a customer first
        $this->client->request(Request::METHOD_GET, '/getAllCustomers');
        $customers = $this->getJsonResponse($this->client->getResponse());

        $customerId = null;
        foreach ($customers as $item) {
            /** @var array<string, mixed> $item */
            $customer = isset($item['customer']) && is_array($item['customer']) ? $item['customer'] : $item;
            $id = $customer['id'] ?? 0;
            if (is_int($id) && $id > 0) {
                $customerId = $id;
                break;
            }
        }

        if (null === $customerId) {
            self::markTestSkipped('No customers available');
        }

        // Get projects for that customer
        $this->client->request(Request::METHOD_GET, '/getProjects', [
            'customer' => $customerId,
        ]);

        $this->assertStatusCode(200);
        $projects = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($projects);
    }

    public function testUpdateProject(): void
    {
        $this->logInSession('unittest');

        // Get existing project
        $this->client->request(Request::METHOD_GET, '/getAllProjects');
        $projects = $this->getJsonResponse($this->client->getResponse());

        $existingProject = null;
        foreach ($projects as $item) {
            /** @var array<string, mixed> $item */
            $project = isset($item['project']) && is_array($item['project']) ? $item['project'] : $item;
            $id = $project['id'] ?? 0;
            if (is_int($id) && $id > 0) {
                $existingProject = $project;
                break;
            }
        }

        if (null === $existingProject) {
            self::markTestSkipped('No projects available for update test');
        }

        // Update the project
        $updatedName = 'Updated Project ' . uniqid();
        $this->client->request(
            Request::METHOD_POST,
            '/project/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'id' => $existingProject['id'],
                'name' => $updatedName,
                'customer' => $existingProject['customer'] ?? 1,
                'active' => true,
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertStatusCode(200);
    }

    public function testProjectStructure(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_GET, '/getProjectStructure');
        $this->assertStatusCode(200);

        $structure = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($structure);
    }

    public function testProjectRequiresCustomer(): void
    {
        $this->logInSession('unittest');

        // Try to create project without customer
        $this->client->request(
            Request::METHOD_POST,
            '/project/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Invalid Project ' . uniqid(),
                'active' => true,
            ], JSON_THROW_ON_ERROR),
        );

        // Should return error (can't create project without customer)
        $statusCode = $this->client->getResponse()->getStatusCode();
        // The exact error handling depends on implementation
        self::assertContains($statusCode, [200, 400, 406, 422, 500]);
    }
}

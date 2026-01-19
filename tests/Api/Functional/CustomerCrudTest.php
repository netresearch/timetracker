<?php

declare(strict_types=1);

namespace Tests\Api\Functional;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function is_array;
use function is_int;
use function is_string;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * API Functional Tests - Customer CRUD Operations.
 *
 * These tests verify actual CRUD operations with real database.
 * Use for CI/full test runs.
 *
 * @internal
 *
 * @coversNothing
 */
final class CustomerCrudTest extends AbstractWebTestCase
{
    public function testCreateCustomer(): void
    {
        $this->logInSession('unittest');

        // First get a team to associate with (customers need teams unless global)
        $this->client->request(Request::METHOD_GET, '/getAllTeams');
        $teams = $this->getJsonResponse($this->client->getResponse());

        $teamId = null;
        foreach ($teams as $item) {
            /** @var array<string, mixed> $item */
            $team = isset($item['team']) && is_array($item['team']) ? $item['team'] : $item;
            $id = $team['id'] ?? 0;
            if (is_int($id) && $id > 0) {
                $teamId = $id;
                break;
            }
        }

        // Create a new customer
        $this->client->request(
            Request::METHOD_POST,
            '/customer/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'E2E Test Customer ' . uniqid(),
                'active' => true,
                'global' => null === $teamId,
                'teams' => null !== $teamId ? [$teamId] : [],
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertStatusCode(200);
        $response = $this->getJsonResponse($this->client->getResponse());

        // Customer save returns array: [id, name, active, global, teamIds]
        self::assertIsArray($response);
        self::assertCount(5, $response);
        self::assertNotNull($response[0]); // id
    }

    public function testReadCustomer(): void
    {
        $this->logInSession('unittest');

        // Get all customers
        $this->client->request(Request::METHOD_GET, '/getAllCustomers');
        $this->assertStatusCode(200);

        $customers = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($customers);
        self::assertNotEmpty($customers);

        // Get first valid customer (skip id=0)
        $validCustomer = null;
        foreach ($customers as $item) {
            /** @var array<string, mixed> $item */
            $customer = isset($item['customer']) && is_array($item['customer']) ? $item['customer'] : $item;
            $id = $customer['id'] ?? 0;
            if (is_int($id) && $id > 0) {
                $validCustomer = $customer;
                break;
            }
        }

        if (null === $validCustomer) {
            self::markTestSkipped('No valid customers in database');
        }

        // Get single customer
        $this->client->request(Request::METHOD_GET, '/getCustomer', [
            'id' => $validCustomer['id'],
        ]);

        $this->assertStatusCode(200);
    }

    public function testUpdateCustomer(): void
    {
        $this->logInSession('unittest');

        // First get a team
        $this->client->request(Request::METHOD_GET, '/getAllTeams');
        $teams = $this->getJsonResponse($this->client->getResponse());

        $teamId = null;
        foreach ($teams as $item) {
            /** @var array<string, mixed> $item */
            $team = isset($item['team']) && is_array($item['team']) ? $item['team'] : $item;
            $id = $team['id'] ?? 0;
            if (is_int($id) && $id > 0) {
                $teamId = $id;
                break;
            }
        }

        // First create a customer to update
        $uniqueName = 'Update Test Customer ' . uniqid();
        $this->client->request(
            Request::METHOD_POST,
            '/customer/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => $uniqueName,
                'active' => true,
                'global' => null === $teamId,
                'teams' => null !== $teamId ? [$teamId] : [],
            ], JSON_THROW_ON_ERROR),
        );

        $createResponse = $this->getJsonResponse($this->client->getResponse());
        $customerId = $createResponse[0] ?? null;

        if (null === $customerId) {
            self::markTestSkipped('Could not create test customer');
        }

        // Update the customer
        $updatedName = 'Updated Customer ' . uniqid();
        $this->client->request(
            Request::METHOD_POST,
            '/customer/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'id' => $customerId,
                'name' => $updatedName,
                'active' => true,
                'global' => null === $teamId,
                'teams' => null !== $teamId ? [$teamId] : [],
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertStatusCode(200);
        $updateResponse = $this->getJsonResponse($this->client->getResponse());

        // Customer save returns array: [id, name, active, global, teamIds]
        self::assertIsArray($updateResponse);
        self::assertSame($updatedName, $updateResponse[1]);
    }

    public function testDeleteCustomer(): void
    {
        $this->logInSession('unittest');

        // First get a team
        $this->client->request(Request::METHOD_GET, '/getAllTeams');
        $teams = $this->getJsonResponse($this->client->getResponse());

        $teamId = null;
        foreach ($teams as $item) {
            /** @var array<string, mixed> $item */
            $team = isset($item['team']) && is_array($item['team']) ? $item['team'] : $item;
            $id = $team['id'] ?? 0;
            if (is_int($id) && $id > 0) {
                $teamId = $id;
                break;
            }
        }

        // First create a customer to delete
        $uniqueName = 'Delete Test Customer ' . uniqid();
        $this->client->request(
            Request::METHOD_POST,
            '/customer/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => $uniqueName,
                'active' => true,
                'global' => null === $teamId,
                'teams' => null !== $teamId ? [$teamId] : [],
            ], JSON_THROW_ON_ERROR),
        );

        $createResponse = $this->getJsonResponse($this->client->getResponse());
        $customerId = $createResponse[0] ?? null;

        if (null === $customerId) {
            self::markTestSkipped('Could not create test customer');
        }

        // Delete the customer
        $this->client->request(
            Request::METHOD_POST,
            '/customer/delete',
            ['id' => $customerId],
        );

        // Should return 200 or redirect
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 302]);
    }

    public function testCustomerValidation(): void
    {
        $this->logInSession('unittest');

        // Try to create customer without name
        $this->client->request(
            Request::METHOD_POST,
            '/customer/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'active' => true,
            ], JSON_THROW_ON_ERROR),
        );

        // Should return validation error
        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [400, 422]);
    }

    public function testCustomerProjectRelationship(): void
    {
        $this->logInSession('unittest');

        // Get customers with projects
        $this->client->request(Request::METHOD_GET, '/getAllCustomers');
        $customers = $this->getJsonResponse($this->client->getResponse());

        self::assertIsArray($customers);

        // Get projects
        $this->client->request(Request::METHOD_GET, '/getAllProjects');
        $projects = $this->getJsonResponse($this->client->getResponse());

        self::assertIsArray($projects);

        // Verify relationship integrity - all projects should reference valid customers
        $customerIds = [];
        foreach ($customers as $item) {
            /** @var array<string, mixed> $item */
            $customer = isset($item['customer']) && is_array($item['customer']) ? $item['customer'] : $item;
            $customerIds[] = $customer['id'] ?? 0;
        }

        foreach ($projects as $item) {
            /** @var array<string, mixed> $item */
            $project = isset($item['project']) && is_array($item['project']) ? $item['project'] : $item;
            $customerId = $project['customer'] ?? null;
            if (is_int($customerId) && $customerId > 0) {
                self::assertContains(
                    $customerId,
                    $customerIds,
                    sprintf('Project %s references non-existent customer %d', is_string($project['name'] ?? null) ? $project['name'] : '', $customerId),
                );
            }
        }
    }
}

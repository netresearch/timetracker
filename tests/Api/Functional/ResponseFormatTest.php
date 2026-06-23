<?php

declare(strict_types=1);

namespace Tests\Api\Functional;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function count;
use function is_int;

use const JSON_THROW_ON_ERROR;

/**
 * API Response Format Tests.
 *
 * These tests verify that the real API responses have the expected structure
 * by issuing authenticated requests against the actual controllers, repositories
 * and serializers. They require database access and run as part of the
 * DB-backed `test-integration` CI job (the `api-functional` suite is mapped by
 * directory in phpunit.xml.dist).
 *
 * @internal
 *
 * @coversNothing
 */
final class ResponseFormatTest extends AbstractWebTestCase
{
    // =========================================================================
    // Customer Response Format
    // =========================================================================

    public function testCustomerJsonStructure(): void
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_GET, '/getAllCustomers');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $decoded = $this->getJsonResponse($this->client->getResponse());
        self::assertNotEmpty($decoded);

        $first = $decoded[0];
        self::assertIsArray($first);
        self::assertArrayHasKey('customer', $first);

        $customer = $first['customer'];
        self::assertIsArray($customer);
        self::assertArrayHasKey('id', $customer);
        self::assertArrayHasKey('name', $customer);
        self::assertArrayHasKey('active', $customer);
        self::assertArrayHasKey('global', $customer);
        self::assertArrayHasKey('teams', $customer);

        self::assertIsInt($customer['id']);
        self::assertIsString($customer['name']);
        self::assertIsBool($customer['active']);
        self::assertIsBool($customer['global']);
        self::assertIsArray($customer['teams']);
    }

    public function testCustomerListResponseFormat(): void
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_GET, '/getAllCustomers');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $decoded = $this->getJsonResponse($this->client->getResponse());
        self::assertNotEmpty($decoded);

        foreach ($decoded as $item) {
            self::assertIsArray($item);
            self::assertArrayHasKey('customer', $item);
            self::assertIsArray($item['customer']);
            self::assertArrayHasKey('id', $item['customer']);
            self::assertArrayHasKey('name', $item['customer']);
        }
    }

    // =========================================================================
    // Project Response Format
    // =========================================================================

    public function testProjectJsonStructure(): void
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_GET, '/getAllProjects');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $decoded = $this->getJsonResponse($this->client->getResponse());
        self::assertNotEmpty($decoded);

        $first = $decoded[0];
        self::assertIsArray($first);
        self::assertArrayHasKey('project', $first);

        $project = $first['project'];
        self::assertIsArray($project);
        self::assertArrayHasKey('id', $project);
        self::assertArrayHasKey('name', $project);
        self::assertArrayHasKey('active', $project);
        self::assertArrayHasKey('global', $project);
        self::assertArrayHasKey('customer', $project);

        self::assertIsInt($project['id']);
        self::assertIsString($project['name']);
        self::assertIsBool($project['active']);
        self::assertIsBool($project['global']);
    }

    public function testProjectListResponseFormat(): void
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_GET, '/getAllProjects');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $decoded = $this->getJsonResponse($this->client->getResponse());
        self::assertNotEmpty($decoded);

        foreach ($decoded as $item) {
            self::assertIsArray($item);
            self::assertArrayHasKey('project', $item);
            self::assertIsArray($item['project']);
            self::assertArrayHasKey('id', $item['project']);
            self::assertArrayHasKey('name', $item['project']);
        }
    }

    // =========================================================================
    // Activity Response Format
    // =========================================================================

    public function testActivityJsonStructure(): void
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_GET, '/getActivities');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $decoded = $this->getJsonResponse($this->client->getResponse());
        self::assertNotEmpty($decoded);

        $first = $decoded[0];
        self::assertIsArray($first);
        self::assertArrayHasKey('activity', $first);

        $activity = $first['activity'];
        self::assertIsArray($activity);
        self::assertArrayHasKey('id', $activity);
        self::assertArrayHasKey('name', $activity);
        self::assertArrayHasKey('needsTicket', $activity);
        self::assertArrayHasKey('factor', $activity);

        self::assertIsInt($activity['id']);
        self::assertIsString($activity['name']);
        self::assertIsBool($activity['needsTicket']);
        self::assertIsNumeric($activity['factor']); // int or float in JSON
    }

    // =========================================================================
    // User Response Format
    // =========================================================================

    public function testUserJsonStructure(): void
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_GET, '/getAllUsers');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $decoded = $this->getJsonResponse($this->client->getResponse());
        self::assertNotEmpty($decoded);

        $first = $decoded[0];
        self::assertIsArray($first);
        self::assertArrayHasKey('user', $first);

        $user = $first['user'];
        self::assertIsArray($user);
        self::assertArrayHasKey('id', $user);
        self::assertArrayHasKey('username', $user);
        self::assertArrayHasKey('abbr', $user);
        self::assertArrayHasKey('type', $user);

        self::assertIsInt($user['id']);
        self::assertIsString($user['username']);
        self::assertIsString($user['abbr']);
        self::assertIsString($user['type']);
    }

    // =========================================================================
    // Team Response Format
    // =========================================================================

    public function testTeamJsonStructure(): void
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_GET, '/getAllTeams');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $decoded = $this->getJsonResponse($this->client->getResponse());
        self::assertNotEmpty($decoded);

        $first = $decoded[0];
        self::assertIsArray($first);
        self::assertArrayHasKey('team', $first);

        $team = $first['team'];
        self::assertIsArray($team);
        self::assertArrayHasKey('id', $team);
        self::assertArrayHasKey('name', $team);

        self::assertIsInt($team['id']);
        self::assertIsString($team['name']);
    }

    // =========================================================================
    // Time Summary Response Format
    // =========================================================================

    public function testTimeSummaryResponseFormat(): void
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_GET, '/getTimeSummary');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $decoded = $this->getJsonResponse($this->client->getResponse());
        self::assertArrayHasKey('today', $decoded);
        self::assertArrayHasKey('week', $decoded);
        self::assertArrayHasKey('month', $decoded);

        // Each period is an array with duration + count (see EntryRepository::getWorkByUser).
        foreach (['today', 'week', 'month'] as $period) {
            self::assertIsArray($decoded[$period]);
            self::assertArrayHasKey('duration', $decoded[$period]);
            self::assertArrayHasKey('count', $decoded[$period]);
            self::assertIsInt($decoded[$period]['duration']);
            self::assertIsInt($decoded[$period]['count']);
        }
    }

    // =========================================================================
    // Customer Save Response Format
    // =========================================================================

    public function testCustomerSaveResponseFormat(): void
    {
        $this->logInSession('unittest');

        // A customer needs a team unless it is global.
        $this->client->request(Request::METHOD_GET, '/getAllTeams');
        $teams = $this->getJsonResponse($this->client->getResponse());

        $teamId = null;
        foreach ($teams as $item) {
            self::assertIsArray($item);
            self::assertArrayHasKey('team', $item);
            self::assertIsArray($item['team']);
            $id = $item['team']['id'] ?? 0;
            if (is_int($id) && $id > 0) {
                $teamId = $id;
                break;
            }
        }

        $this->client->request(
            Request::METHOD_POST,
            '/customer/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Format Test Customer ' . uniqid(),
                'active' => true,
                'global' => null === $teamId,
                'teams' => null !== $teamId ? [$teamId] : [],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $decoded = $this->getJsonResponse($this->client->getResponse());

        // Customer save returns array: [id, name, active, global, teamIds].
        self::assertCount(5, $decoded);
        self::assertIsInt($decoded[0]);    // id
        self::assertIsString($decoded[1]); // name
        self::assertIsBool($decoded[2]);   // active
        self::assertIsBool($decoded[3]);   // global
        self::assertIsArray($decoded[4]);  // teamIds
    }

    // =========================================================================
    // Project Save Response Format
    // =========================================================================

    public function testProjectSaveResponseFormat(): void
    {
        $this->logInSession('unittest');

        // Pick an existing customer to attach the project to.
        $this->client->request(Request::METHOD_GET, '/getAllCustomers');
        $customers = $this->getJsonResponse($this->client->getResponse());

        $customerId = null;
        foreach ($customers as $item) {
            self::assertIsArray($item);
            self::assertArrayHasKey('customer', $item);
            self::assertIsArray($item['customer']);
            $id = $item['customer']['id'] ?? 0;
            if (is_int($id) && $id > 0) {
                $customerId = $id;
                break;
            }
        }
        self::assertNotNull($customerId, 'Seed must contain at least one customer');

        $this->client->request(
            Request::METHOD_POST,
            '/project/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Format Test Project ' . uniqid(),
                'customer' => $customerId,
                'active' => true,
                'global' => false,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $decoded = $this->getJsonResponse($this->client->getResponse());

        // Project save returns positional array: [id, name, customerId, jiraId].
        self::assertGreaterThanOrEqual(4, count($decoded));
        self::assertIsInt($decoded[0]);    // id
        self::assertIsString($decoded[1]); // name
    }
}

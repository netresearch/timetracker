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
    /**
     * Log in, GET a list endpoint, and assert the shape of the first wrapped row.
     *
     * Asserts 200, decodes the body, requires it non-empty, then checks that the
     * first item wraps its payload under $wrapperKey and that each named key is
     * present on the payload with the expected type.
     *
     * @param array<int, string> $stringKeys  keys whose value must be a string
     * @param array<int, string> $boolKeys    keys whose value must be a bool
     * @param array<int, string> $intKeys     keys whose value must be an int
     * @param array<int, string> $arrayKeys   keys whose value must be an array
     * @param array<int, string> $numericKeys keys whose value must be numeric (int|float)
     * @param array<int, string> $presentKeys keys that must merely be present
     */
    private function assertListEndpoint(
        string $path,
        string $wrapperKey,
        array $stringKeys = [],
        array $boolKeys = [],
        array $intKeys = [],
        array $arrayKeys = [],
        array $numericKeys = [],
        array $presentKeys = [],
    ): void {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_GET, $path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $decoded = $this->getJsonResponse($this->client->getResponse());
        self::assertNotEmpty($decoded);

        $first = $decoded[0];
        self::assertIsArray($first);
        self::assertArrayHasKey($wrapperKey, $first);

        $payload = $first[$wrapperKey];
        self::assertIsArray($payload);

        foreach ([...$stringKeys, ...$boolKeys, ...$intKeys, ...$arrayKeys, ...$numericKeys, ...$presentKeys] as $key) {
            self::assertArrayHasKey($key, $payload);
        }

        foreach ($stringKeys as $key) {
            self::assertIsString($payload[$key]);
        }

        foreach ($boolKeys as $key) {
            self::assertIsBool($payload[$key]);
        }

        foreach ($intKeys as $key) {
            self::assertIsInt($payload[$key]);
        }

        foreach ($arrayKeys as $key) {
            self::assertIsArray($payload[$key]);
        }

        foreach ($numericKeys as $key) {
            self::assertIsNumeric($payload[$key]);
        }
    }

    /**
     * Log in, GET a list endpoint, and assert every wrapped row carries id+name.
     *
     * @return array<mixed, mixed> the decoded list, for callers needing the rows
     */
    private function assertWrappedListHasIdName(string $path, string $wrapperKey): array
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_GET, $path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $decoded = $this->getJsonResponse($this->client->getResponse());
        self::assertNotEmpty($decoded);

        foreach ($decoded as $item) {
            self::assertIsArray($item);
            self::assertArrayHasKey($wrapperKey, $item);
            self::assertIsArray($item[$wrapperKey]);
            self::assertArrayHasKey('id', $item[$wrapperKey]);
            self::assertArrayHasKey('name', $item[$wrapperKey]);
        }

        return $decoded;
    }

    /**
     * Find the first positive integer id under $wrapperKey in a wrapped list.
     *
     * @param array<mixed, mixed> $list rows shaped like [['<wrapperKey>' => ['id' => int, ...]], ...]
     */
    private function firstIdInWrappedList(array $list, string $wrapperKey): ?int
    {
        foreach ($list as $item) {
            self::assertIsArray($item);
            self::assertArrayHasKey($wrapperKey, $item);
            self::assertIsArray($item[$wrapperKey]);
            $id = $item[$wrapperKey]['id'] ?? 0;
            if (is_int($id) && $id > 0) {
                return $id;
            }
        }

        return null;
    }

    // =========================================================================
    // Customer Response Format
    // =========================================================================

    public function testCustomerJsonStructure(): void
    {
        $this->assertListEndpoint(
            '/getAllCustomers',
            'customer',
            stringKeys: ['name'],
            boolKeys: ['active', 'global'],
            intKeys: ['id'],
            arrayKeys: ['teams'],
        );
    }

    public function testCustomerListResponseFormat(): void
    {
        $this->assertWrappedListHasIdName('/getAllCustomers', 'customer');
    }

    // =========================================================================
    // Project Response Format
    // =========================================================================

    public function testProjectJsonStructure(): void
    {
        $this->assertListEndpoint(
            '/getAllProjects',
            'project',
            stringKeys: ['name'],
            boolKeys: ['active', 'global'],
            intKeys: ['id'],
            presentKeys: ['customer'],
        );
    }

    public function testProjectListResponseFormat(): void
    {
        $this->assertWrappedListHasIdName('/getAllProjects', 'project');
    }

    // =========================================================================
    // Activity Response Format
    // =========================================================================

    public function testActivityJsonStructure(): void
    {
        $this->assertListEndpoint(
            '/getActivities',
            'activity',
            stringKeys: ['name'],
            boolKeys: ['needsTicket'],
            intKeys: ['id'],
            numericKeys: ['factor'], // int or float in JSON
        );
    }

    // =========================================================================
    // User Response Format
    // =========================================================================

    public function testUserJsonStructure(): void
    {
        $this->assertListEndpoint(
            '/getAllUsers',
            'user',
            stringKeys: ['username', 'abbr', 'type'],
            intKeys: ['id'],
        );
    }

    // =========================================================================
    // Team Response Format
    // =========================================================================

    public function testTeamJsonStructure(): void
    {
        $this->assertListEndpoint(
            '/getAllTeams',
            'team',
            stringKeys: ['name'],
            intKeys: ['id'],
        );
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
        // A customer needs a team unless it is global.
        $teams = $this->assertWrappedListHasIdName('/getAllTeams', 'team');
        $teamId = $this->firstIdInWrappedList($teams, 'team');

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
        // Pick an existing customer to attach the project to.
        $customers = $this->assertWrappedListHasIdName('/getAllCustomers', 'customer');
        $customerId = $this->firstIdInWrappedList($customers, 'customer');
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

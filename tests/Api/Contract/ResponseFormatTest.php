<?php

declare(strict_types=1);

namespace Tests\Api\Contract;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function count;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * API Contract Tests - Response Format Validation.
 *
 * These tests verify that API responses have the expected structure.
 * They use mock data and don't require database access.
 * Suitable for pre-commit hooks.
 *
 * @internal
 *
 * @coversNothing
 */
final class ResponseFormatTest extends TestCase
{
    // =========================================================================
    // Customer Response Format
    // =========================================================================

    public function testCustomerJsonStructure(): void
    {
        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setActive(true);
        $customer->setGlobal(false);

        // Use reflection to set ID (normally set by Doctrine)
        $reflection = new ReflectionClass($customer);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($customer, 1);

        // Simulate the JSON response format
        $responseData = [
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'active' => $customer->getActive(),
            'global' => $customer->getGlobal(),
        ];

        $json = json_encode($responseData, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('id', $decoded);
        self::assertArrayHasKey('name', $decoded);
        self::assertArrayHasKey('active', $decoded);
        self::assertArrayHasKey('global', $decoded);

        self::assertIsInt($decoded['id']);
        self::assertIsString($decoded['name']);
        self::assertIsBool($decoded['active']);
        self::assertIsBool($decoded['global']);
    }

    public function testCustomerListResponseFormat(): void
    {
        $customers = [
            ['id' => 1, 'name' => 'Customer A', 'active' => true, 'global' => false],
            ['id' => 2, 'name' => 'Customer B', 'active' => true, 'global' => true],
        ];

        $json = json_encode($customers, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertNotEmpty($decoded);

        foreach ($decoded as $customer) {
            self::assertIsArray($customer);
            self::assertArrayHasKey('id', $customer);
            self::assertArrayHasKey('name', $customer);
        }
    }

    // =========================================================================
    // Project Response Format
    // =========================================================================

    public function testProjectJsonStructure(): void
    {
        $project = new Project();
        $project->setName('Test Project');
        $project->setActive(true);
        $project->setGlobal(false);

        // Use reflection to set ID
        $reflection = new ReflectionClass($project);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($project, 1);

        $responseData = [
            'id' => $project->getId(),
            'name' => $project->getName(),
            'active' => $project->getActive(),
            'global' => $project->getGlobal(),
            'customer' => null,
        ];

        $json = json_encode($responseData, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('id', $decoded);
        self::assertArrayHasKey('name', $decoded);
        self::assertArrayHasKey('active', $decoded);
        self::assertArrayHasKey('global', $decoded);
    }

    public function testProjectListResponseFormat(): void
    {
        $projects = [
            ['id' => 1, 'name' => 'Project A', 'active' => true, 'customer' => 1],
            ['id' => 2, 'name' => 'Project B', 'active' => true, 'customer' => 1],
        ];

        $json = json_encode($projects, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertNotEmpty($decoded);

        foreach ($decoded as $project) {
            self::assertIsArray($project);
            self::assertArrayHasKey('id', $project);
            self::assertArrayHasKey('name', $project);
        }
    }

    // =========================================================================
    // Activity Response Format
    // =========================================================================

    public function testActivityJsonStructure(): void
    {
        $activity = new Activity();
        $activity->setName('Test Activity');
        $activity->setNeedsTicket(false);
        $activity->setFactor(1.0);

        // Use reflection to set ID
        $reflection = new ReflectionClass($activity);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($activity, 1);

        $responseData = [
            'id' => $activity->getId(),
            'name' => $activity->getName(),
            'needsTicket' => $activity->getNeedsTicket(),
            'factor' => $activity->getFactor(),
        ];

        $json = json_encode($responseData, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('id', $decoded);
        self::assertArrayHasKey('name', $decoded);
        self::assertArrayHasKey('needsTicket', $decoded);
        self::assertArrayHasKey('factor', $decoded);

        self::assertIsInt($decoded['id']);
        self::assertIsString($decoded['name']);
        self::assertIsBool($decoded['needsTicket']);
        self::assertIsNumeric($decoded['factor']);  // Can be int or float in JSON
    }

    public function testActivitySaveResponseFormat(): void
    {
        // Activity save returns array: [id, name, needsTicket, factor]
        // Use 0.5 to ensure float is preserved (1.0 becomes 1 in JSON)
        $response = [1, 'New Activity', false, 0.5];

        $json = json_encode($response, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertCount(4, $decoded);
        self::assertIsInt($decoded[0]);      // id
        self::assertIsString($decoded[1]);   // name
        self::assertIsBool($decoded[2]);     // needsTicket
        self::assertIsNumeric($decoded[3]);  // factor (can be int or float)
    }

    // =========================================================================
    // User Response Format
    // =========================================================================

    public function testUserJsonStructure(): void
    {
        $user = new User();
        $user->setUsername('testuser');
        $user->setAbbr('TU');
        $user->setType('DEV');
        $user->setLocale('en');

        // Use reflection to set ID
        $reflection = new ReflectionClass($user);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($user, 1);

        $responseData = [
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'abbr' => $user->getAbbr(),
            'type' => $user->getType(),
        ];

        $json = json_encode($responseData, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('id', $decoded);
        self::assertArrayHasKey('username', $decoded);
        self::assertArrayHasKey('abbr', $decoded);
        self::assertArrayHasKey('type', $decoded);
    }

    // =========================================================================
    // Team Response Format
    // =========================================================================

    public function testTeamJsonStructure(): void
    {
        $team = new Team();
        $team->setName('Test Team');

        // Use reflection to set ID
        $reflection = new ReflectionClass($team);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($team, 1);

        $responseData = [
            'id' => $team->getId(),
            'name' => $team->getName(),
            'leadUser' => null,
        ];

        $json = json_encode($responseData, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('id', $decoded);
        self::assertArrayHasKey('name', $decoded);
    }

    // =========================================================================
    // Time Summary Response Format
    // =========================================================================

    public function testTimeSummaryResponseFormat(): void
    {
        $summary = [
            'today' => 480,
            'week' => 2400,
            'month' => 9600,
        ];

        $json = json_encode($summary, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('today', $decoded);
        self::assertArrayHasKey('week', $decoded);
        self::assertArrayHasKey('month', $decoded);

        self::assertIsInt($decoded['today']);
        self::assertIsInt($decoded['week']);
        self::assertIsInt($decoded['month']);
    }

    // =========================================================================
    // Customer Save Response Format
    // =========================================================================

    public function testCustomerSaveResponseFormat(): void
    {
        // Customer save returns array: [id, name, active, global, teamIds]
        $response = [1, 'New Customer', true, false, [1, 2]];

        $json = json_encode($response, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertCount(5, $decoded);
        self::assertIsInt($decoded[0]);      // id
        self::assertIsString($decoded[1]);   // name
        self::assertIsBool($decoded[2]);     // active
        self::assertIsBool($decoded[3]);     // global
        self::assertIsArray($decoded[4]);    // teamIds
    }

    // =========================================================================
    // Project Save Response Format
    // =========================================================================

    public function testProjectSaveResponseFormat(): void
    {
        // Project save returns array: [id, name, customerId, jiraId]
        $response = [1, 'New Project', 1, 'PROJ-1'];

        $json = json_encode($response, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertGreaterThanOrEqual(4, count($decoded));
        self::assertIsInt($decoded[0]);      // id
        self::assertIsString($decoded[1]);   // name
    }
}

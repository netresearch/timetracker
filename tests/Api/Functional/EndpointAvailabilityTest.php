<?php

declare(strict_types=1);

namespace Tests\Api\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function sprintf;

/**
 * API Functional Tests - Endpoint Availability.
 *
 * Smoke tests to verify all API endpoints are available and don't error.
 * Requires database access - use for CI/full test runs.
 *
 * @internal
 *
 * @coversNothing
 */
final class EndpointAvailabilityTest extends AbstractWebTestCase
{
    #[DataProvider('getEndpointsProvider')]
    public function testGetEndpointIsAvailable(string $endpoint): void
    {
        $this->client->request(Request::METHOD_GET, $endpoint);

        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [200, 406], // 406 is acceptable for interpretation endpoints without filters
            sprintf('Endpoint %s returned unexpected status %d', $endpoint, $this->client->getResponse()->getStatusCode()),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function getEndpointsProvider(): array
    {
        return [
            // Core data endpoints
            'getData' => ['/getData'],
            'getTimeSummary' => ['/getTimeSummary'],

            // Customer endpoints
            'getAllCustomers' => ['/getAllCustomers'],
            'getCustomers' => ['/getCustomers'],

            // Project endpoints
            'getAllProjects' => ['/getAllProjects'],
            'getProjectStructure' => ['/getProjectStructure'],

            // Activity endpoints
            'getActivities' => ['/getActivities'],

            // User endpoints
            'getAllUsers' => ['/getAllUsers'],
            'getUsers' => ['/getUsers'],

            // Team endpoints
            'getAllTeams' => ['/getAllTeams'],

            // Contract endpoints
            'getContracts' => ['/getContracts'],

            // Ticket system endpoints
            'getTicketSystems' => ['/getTicketSystems'],

            // Preset endpoints
            'getAllPresets' => ['/getAllPresets'],

            // Status endpoints
            'statusCheck' => ['/status/check'],
            'statusPage' => ['/status/page'],

            // Interpretation endpoints
            'interpretationActivity' => ['/interpretation/activity'],
            'interpretationCustomer' => ['/interpretation/customer'],
            'interpretationProject' => ['/interpretation/project'],
            'interpretationTicket' => ['/interpretation/ticket'],
            'interpretationUser' => ['/interpretation/user'],
            'interpretationTime' => ['/interpretation/time'],
            'interpretationEntries' => ['/interpretation/entries'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Api\Functional;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function sprintf;

/**
 * API Functional Tests - Data Integrity.
 *
 * These tests verify data relationships and consistency.
 * Use for CI/full test runs.
 *
 * @internal
 *
 * @coversNothing
 */
final class DataIntegrityTest extends AbstractWebTestCase
{
    /**
     * Extract integer ID from mixed value.
     */
    private function extractId(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Extract string from mixed value.
     */
    private function extractString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    public function testCustomerProjectRelationshipConsistency(): void
    {
        $this->logInSession('unittest');

        // Get all customers
        $this->client->request(Request::METHOD_GET, '/getAllCustomers');
        $customers = $this->getJsonResponse($this->client->getResponse());

        /** @var list<int> $customerIds */
        $customerIds = [];
        foreach ($customers as $item) {
            /** @var array<string, mixed> $item */
            $customer = isset($item['customer']) && is_array($item['customer']) ? $item['customer'] : $item;
            $customerIds[] = $this->extractId($customer['id'] ?? null);
        }

        // Get all projects
        $this->client->request(Request::METHOD_GET, '/getAllProjects');
        $projects = $this->getJsonResponse($this->client->getResponse());

        // Verify all projects reference valid customers
        foreach ($projects as $item) {
            /** @var array<string, mixed> $item */
            $project = isset($item['project']) && is_array($item['project']) ? $item['project'] : $item;
            $customerId = $project['customer'] ?? null;
            if (is_int($customerId) && $customerId > 0) {
                self::assertContains(
                    $customerId,
                    $customerIds,
                    sprintf('Project "%s" references non-existent customer ID %d', $this->extractString($project['name'] ?? null), $customerId),
                );
            }
        }
    }

    public function testUserTeamRelationshipConsistency(): void
    {
        $this->logInSession('unittest');

        // Get all users
        $this->client->request(Request::METHOD_GET, '/getAllUsers');
        $users = $this->getJsonResponse($this->client->getResponse());

        // Get all teams
        $this->client->request(Request::METHOD_GET, '/getAllTeams');
        $teamsResponse = $this->client->getResponse();

        if (200 !== $teamsResponse->getStatusCode()) {
            self::markTestSkipped('Teams endpoint not available');
        }

        $teams = $this->getJsonResponse($teamsResponse);
        /** @var list<int> $teamIds */
        $teamIds = [];
        foreach ($teams as $item) {
            /** @var array<string, mixed> $item */
            $team = isset($item['team']) && is_array($item['team']) ? $item['team'] : $item;
            $teamIds[] = $this->extractId($team['id'] ?? null);
        }

        // Verify user-team relationships
        foreach ($users as $item) {
            /** @var array<string, mixed> $item */
            $user = isset($item['user']) && is_array($item['user']) ? $item['user'] : $item;
            $userTeams = $user['teams'] ?? null;
            if (is_array($userTeams)) {
                foreach ($userTeams as $teamId) {
                    if (is_int($teamId) && $teamId > 0) {
                        self::assertContains(
                            $teamId,
                            $teamIds,
                            sprintf('User "%s" references non-existent team ID %d', $this->extractString($user['username'] ?? null), $teamId),
                        );
                    }
                }
            }
        }
    }

    public function testProjectLeadReferencesValidUser(): void
    {
        $this->logInSession('unittest');

        // Get all users
        $this->client->request(Request::METHOD_GET, '/getAllUsers');
        $users = $this->getJsonResponse($this->client->getResponse());

        /** @var list<int> $userIds */
        $userIds = [];
        foreach ($users as $item) {
            /** @var array<string, mixed> $item */
            $user = isset($item['user']) && is_array($item['user']) ? $item['user'] : $item;
            $userIds[] = $this->extractId($user['id'] ?? null);
        }

        // Get all projects
        $this->client->request(Request::METHOD_GET, '/getAllProjects');
        $projects = $this->getJsonResponse($this->client->getResponse());

        // Verify project leads reference valid users
        foreach ($projects as $item) {
            /** @var array<string, mixed> $item */
            $project = isset($item['project']) && is_array($item['project']) ? $item['project'] : $item;
            $projectLead = $project['projectLead'] ?? null;
            if (is_int($projectLead) && $projectLead > 0) {
                self::assertContains(
                    $projectLead,
                    $userIds,
                    sprintf('Project "%s" has invalid project lead ID %d', $this->extractString($project['name'] ?? null), $projectLead),
                );
            }
            $technicalLead = $project['technicalLead'] ?? null;
            if (is_int($technicalLead) && $technicalLead > 0) {
                self::assertContains(
                    $technicalLead,
                    $userIds,
                    sprintf('Project "%s" has invalid technical lead ID %d', $this->extractString($project['name'] ?? null), $technicalLead),
                );
            }
        }
    }

    public function testTeamLeadReferencesValidUser(): void
    {
        $this->logInSession('unittest');

        // Get all users
        $this->client->request(Request::METHOD_GET, '/getAllUsers');
        $users = $this->getJsonResponse($this->client->getResponse());

        /** @var list<int> $userIds */
        $userIds = [];
        foreach ($users as $item) {
            /** @var array<string, mixed> $item */
            $user = isset($item['user']) && is_array($item['user']) ? $item['user'] : $item;
            $userIds[] = $this->extractId($user['id'] ?? null);
        }

        // Get all teams
        $this->client->request(Request::METHOD_GET, '/getAllTeams');
        $teamsResponse = $this->client->getResponse();

        if (200 !== $teamsResponse->getStatusCode()) {
            self::markTestSkipped('Teams endpoint not available');
        }

        $teams = $this->getJsonResponse($teamsResponse);

        // Verify team leads reference valid users
        foreach ($teams as $item) {
            /** @var array<string, mixed> $item */
            $team = isset($item['team']) && is_array($item['team']) ? $item['team'] : $item;
            $leadUser = $team['leadUser'] ?? null;
            if (is_int($leadUser) && $leadUser > 0) {
                self::assertContains(
                    $leadUser,
                    $userIds,
                    sprintf('Team "%s" has invalid lead user ID %d', $this->extractString($team['name'] ?? null), $leadUser),
                );
            }
        }
    }

    public function testPresetReferencesValidEntities(): void
    {
        $this->logInSession('unittest');

        // Get all required entities
        $this->client->request(Request::METHOD_GET, '/getAllCustomers');
        $customers = $this->getJsonResponse($this->client->getResponse());
        /** @var list<int> $customerIds */
        $customerIds = [];
        foreach ($customers as $item) {
            /** @var array<string, mixed> $item */
            $customer = isset($item['customer']) && is_array($item['customer']) ? $item['customer'] : $item;
            $customerIds[] = $this->extractId($customer['id'] ?? null);
        }

        $this->client->request(Request::METHOD_GET, '/getAllProjects');
        $projects = $this->getJsonResponse($this->client->getResponse());
        /** @var list<int> $projectIds */
        $projectIds = [];
        foreach ($projects as $item) {
            /** @var array<string, mixed> $item */
            $project = isset($item['project']) && is_array($item['project']) ? $item['project'] : $item;
            $projectIds[] = $this->extractId($project['id'] ?? null);
        }

        $this->client->request(Request::METHOD_GET, '/getActivities');
        $activities = $this->getJsonResponse($this->client->getResponse());
        /** @var list<int> $activityIds */
        $activityIds = [];
        foreach ($activities as $item) {
            /** @var array<string, mixed> $item */
            $activity = isset($item['activity']) && is_array($item['activity']) ? $item['activity'] : $item;
            $activityIds[] = $this->extractId($activity['id'] ?? null);
        }

        // Get presets
        $this->client->request(Request::METHOD_GET, '/getAllPresets');
        $presetsResponse = $this->client->getResponse();

        if (200 !== $presetsResponse->getStatusCode()) {
            self::markTestSkipped('Presets endpoint not available');
        }

        $presets = $this->getJsonResponse($presetsResponse);

        // Verify preset references
        foreach ($presets as $item) {
            /** @var array<string, mixed> $item */
            $preset = isset($item['preset']) && is_array($item['preset']) ? $item['preset'] : $item;

            $customerId = $preset['customer'] ?? null;
            if (is_int($customerId) && $customerId > 0) {
                self::assertContains(
                    $customerId,
                    $customerIds,
                    sprintf('Preset "%s" has invalid customer ID', $this->extractString($preset['name'] ?? null)),
                );
            }
            $projectId = $preset['project'] ?? null;
            if (is_int($projectId) && $projectId > 0) {
                self::assertContains(
                    $projectId,
                    $projectIds,
                    sprintf('Preset "%s" has invalid project ID', $this->extractString($preset['name'] ?? null)),
                );
            }
            $activityId = $preset['activity'] ?? null;
            if (is_int($activityId) && $activityId > 0) {
                self::assertContains(
                    $activityId,
                    $activityIds,
                    sprintf('Preset "%s" has invalid activity ID', $this->extractString($preset['name'] ?? null)),
                );
            }
        }
    }

    public function testContractReferencesValidUser(): void
    {
        $this->logInSession('unittest');

        // Get all users
        $this->client->request(Request::METHOD_GET, '/getAllUsers');
        $users = $this->getJsonResponse($this->client->getResponse());
        /** @var list<int> $userIds */
        $userIds = [];
        foreach ($users as $item) {
            /** @var array<string, mixed> $item */
            $user = isset($item['user']) && is_array($item['user']) ? $item['user'] : $item;
            $userIds[] = $this->extractId($user['id'] ?? null);
        }

        // Get contracts
        $this->client->request(Request::METHOD_GET, '/getContracts');
        $contractsResponse = $this->client->getResponse();

        if (200 !== $contractsResponse->getStatusCode()) {
            self::markTestSkipped('Contracts endpoint not available');
        }

        $contracts = $this->getJsonResponse($contractsResponse);

        // Verify contract user references
        foreach ($contracts as $item) {
            /** @var array<string, mixed> $item */
            $contract = isset($item['contract']) && is_array($item['contract']) ? $item['contract'] : $item;
            $userId = $contract['user'] ?? null;
            if (is_int($userId) && $userId > 0) {
                self::assertContains(
                    $userId,
                    $userIds,
                    sprintf('Contract ID %d has invalid user reference', $this->extractId($contract['id'] ?? null)),
                );
            }
        }
    }
}

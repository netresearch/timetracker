<?php

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

/**
 * Comprehensive authorization tests for critical Admin endpoints.
 * 
 * This test suite focuses on high-risk delete actions and admin save operations
 * that require proper PL (Project Leader) authorization to prevent unauthorized
 * data deletion and system configuration changes.
 * 
 * Tests both positive (PL user allowed) and negative (DEV user denied) scenarios
 * to ensure proper access control enforcement.
 * 
 * @internal
 * @coversNothing
 */
final class AuthorizationSecurityTest extends AbstractWebTestCase
{
    // ============================================================================
    // DELETE ACTIONS - Highest Security Risk (Data Loss Prevention)
    // ============================================================================

    /**
     * Test that PL users can delete activities.
     */
    public function testDeleteActivityActionWithPL(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/activity/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Activity ID from test fixtures
        ]));
        
        $this->assertStatusCode(200);
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);
        $this->assertTrue($responseData['success'] ?? false, 'Expected successful activity deletion');
    }

    /**
     * Test that DEV users cannot delete activities - CRITICAL security test.
     * Unauthorized activity deletion could result in loss of time tracking categories.
     */
    public function testDeleteActivityActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/activity/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Activity ID from test fixtures
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that PL users can delete customers.
     */
    public function testDeleteCustomerActionWithPL(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/customer/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 2, // Customer ID from test fixtures (safe to delete)
        ]));
        
        $this->assertStatusCode(200);
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);
        $this->assertTrue($responseData['success'] ?? false, 'Expected successful customer deletion');
    }

    /**
     * Test that DEV users cannot delete customers - CRITICAL security test.
     * Unauthorized customer deletion could result in loss of business data and client relationships.
     */
    public function testDeleteCustomerActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/customer/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 2, // Customer ID from test fixtures
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that PL users can delete projects.
     */
    public function testDeleteProjectActionWithPL(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/project/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 2, // Project ID from test fixtures (safe to delete)
        ]));
        
        $this->assertStatusCode(200);
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);
        $this->assertTrue($responseData['success'] ?? false, 'Expected successful project deletion');
    }

    /**
     * Test that DEV users cannot delete projects - CRITICAL security test.
     * Unauthorized project deletion could result in loss of project history and time entries.
     */
    public function testDeleteProjectActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/project/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 2, // Project ID from test fixtures
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that PL users can delete users.
     */
    public function testDeleteUserActionWithPL(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/user/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 4, // User ID from test fixtures (testGroupByActionUser - safe to delete)
        ]));
        
        $this->assertStatusCode(200);
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);
        $this->assertTrue($responseData['success'] ?? false, 'Expected successful user deletion');
    }

    /**
     * Test that DEV users cannot delete users - CRITICAL security test.
     * Unauthorized user deletion could result in loss of user accounts and access control compromise.
     */
    public function testDeleteUserActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/user/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 4, // User ID from test fixtures
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that PL users can delete ticket systems.
     */
    public function testDeleteTicketSystemActionWithPL(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/ticketsystem/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Ticket system ID from test fixtures
        ]));
        
        $this->assertStatusCode(200);
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);
        $this->assertTrue($responseData['success'] ?? false, 'Expected successful ticket system deletion');
    }

    /**
     * Test that DEV users cannot delete ticket systems - CRITICAL security test.
     * Unauthorized ticket system deletion could break integrations and project workflows.
     */
    public function testDeleteTicketSystemActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/ticketsystem/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Ticket system ID from test fixtures
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that PL users can delete teams.
     */
    public function testDeleteTeamActionWithPL(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/team/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 2, // Team ID from test fixtures (safe to delete)
        ]));
        
        $this->assertStatusCode(200);
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);
        $this->assertTrue($responseData['success'] ?? false, 'Expected successful team deletion');
    }

    /**
     * Test that DEV users cannot delete teams - CRITICAL security test.
     * Unauthorized team deletion could disrupt organizational structure and access controls.
     */
    public function testDeleteTeamActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/team/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 2, // Team ID from test fixtures
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that PL users can delete presets.
     */
    public function testDeletePresetActionWithPL(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/preset/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Preset ID from test fixtures
        ]));
        
        $this->assertStatusCode(200);
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);
        $this->assertTrue($responseData['success'] ?? false, 'Expected successful preset deletion');
    }

    /**
     * Test that DEV users cannot delete presets - CRITICAL security test.
     * Unauthorized preset deletion could remove user productivity tools and configurations.
     */
    public function testDeletePresetActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/preset/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Preset ID from test fixtures
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that PL users can delete contracts.
     */
    public function testDeleteContractActionWithPL(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/contract/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Contract ID from test fixtures
        ]));
        
        $this->assertStatusCode(200);
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);
        $this->assertTrue($responseData['success'] ?? false, 'Expected successful contract deletion');
    }

    /**
     * Test that DEV users cannot delete contracts - CRITICAL security test.
     * Unauthorized contract deletion could result in loss of employment/billing data.
     */
    public function testDeleteContractActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/contract/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Contract ID from test fixtures
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    // ============================================================================
    // SAVE ACTIONS - High Security Risk (Configuration Changes)
    // ============================================================================

    /**
     * Test that PL users can save ticket systems.
     */
    public function testSaveTicketSystemActionWithPL(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/ticketsystem/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => null, // New ticket system
            'name' => 'SecurityTestSystem',
            'type' => 'jira',
            'url' => 'https://test.example.com',
            'ticketUrl' => 'https://test.example.com/ticket/{ticketId}',
        ]));
        
        $this->assertStatusCode(200);
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);
        $this->assertArrayHasKey('id', $responseData, 'Expected ticket system to be created with ID');
        $this->assertEquals('SecurityTestSystem', $responseData['name'] ?? '', 'Expected correct ticket system name');
    }

    /**
     * Test that DEV users cannot save ticket systems - CRITICAL security test.
     * Unauthorized ticket system configuration changes could compromise system integrations.
     */
    public function testSaveTicketSystemActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/ticketsystem/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => null, // New ticket system
            'name' => 'UnauthorizedSystem',
            'type' => 'jira',
            'url' => 'https://malicious.example.com',
            'ticketUrl' => 'https://malicious.example.com/ticket/{ticketId}',
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that PL users can update existing ticket systems.
     */
    public function testUpdateTicketSystemActionWithPL(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/ticketsystem/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Existing ticket system ID
            'name' => 'UpdatedTestSystem',
            'type' => 'jira',
            'url' => 'https://updated.example.com',
            'ticketUrl' => 'https://updated.example.com/ticket/{ticketId}',
        ]));
        
        $this->assertStatusCode(200);
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);
        $this->assertEquals('UpdatedTestSystem', $responseData['name'] ?? '', 'Expected ticket system name to be updated');
    }

    /**
     * Test that DEV users cannot update existing ticket systems - CRITICAL security test.
     * Unauthorized updates could redirect integrations to malicious systems.
     */
    public function testUpdateTicketSystemActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/ticketsystem/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1, // Existing ticket system ID
            'name' => 'HijackedSystem',
            'type' => 'jira',
            'url' => 'https://malicious.example.com',
            'ticketUrl' => 'https://malicious.example.com/steal/{ticketId}',
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    // ============================================================================
    // ADDITIONAL SAVE ACTION AUTHORIZATION TESTS
    // ============================================================================

    /**
     * Test that DEV users cannot save activities - prevents unauthorized activity configuration.
     */
    public function testSaveActivityActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/activity/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => null, // New activity
            'name' => 'UnauthorizedActivity',
            'factor' => 1.5,
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that DEV users cannot save customers - prevents unauthorized customer creation/modification.
     */
    public function testSaveCustomerActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/customer/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => null, // New customer
            'name' => 'UnauthorizedCustomer',
            'active' => true,
            'global' => false,
            'teams' => [1],
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that DEV users cannot save projects - prevents unauthorized project creation/modification.
     */
    public function testSaveProjectActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/project/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => null, // New project
            'customer' => 1,
            'name' => 'UnauthorizedProject',
            'active' => true,
            'global' => false,
            'jiraId' => 'HACK',
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that DEV users cannot save users - prevents unauthorized user account management.
     */
    public function testSaveUserActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/user/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => null, // New user
            'username' => 'unauthorized_user',
            'abbr' => 'UNA',
            'teams' => [1],
            'locale' => 'en',
            'type' => 'PL', // Attempting to create PL user
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that DEV users cannot save teams - prevents unauthorized team management.
     */
    public function testSaveTeamActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/team/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => null, // New team
            'name' => 'UnauthorizedTeam',
            'lead_user_id' => 1,
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that DEV users cannot save contracts - prevents unauthorized contract management.
     */
    public function testSaveContractActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/contract/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => null, // New contract
            'userId' => 2,
            'startDate' => '2024-01-01',
            'endDate' => '2024-12-31',
            'hoursPerWeek' => 40,
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    /**
     * Test that DEV users cannot save presets - prevents unauthorized preset management.
     */
    public function testSavePresetActionWithDEV(): void
    {
        $this->logInSession('developer'); // DEV user
        
        $this->client->request('POST', '/preset/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => null, // New preset
            'name' => 'UnauthorizedPreset',
            'projectId' => 1,
            'activityId' => 1,
        ]));
        
        $this->assertStatusCode(403);
        $this->assertMessage('You are not allowed to perform this action.');
    }

    // ============================================================================
    // EDGE CASE AND SECURITY BOUNDARY TESTS
    // ============================================================================

    /**
     * Test that invalid JSON payloads are handled properly for delete operations.
     */
    public function testDeleteActionWithInvalidJsonPayload(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/activity/delete', [], [], ['CONTENT_TYPE' => 'application/json'], 'invalid-json');
        
        // Should return 400 Bad Request for malformed JSON
        $this->assertStatusCode(400);
    }

    /**
     * Test that missing ID parameter is handled properly for delete operations.
     */
    public function testDeleteActionWithMissingId(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/activity/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([]));
        
        // Should return 422 Unprocessable Entity for missing required field
        $this->assertStatusCode(422);
    }

    /**
     * Test that non-existent ID is handled properly for delete operations.
     */
    public function testDeleteActionWithNonExistentId(): void
    {
        $this->logInSession('unittest'); // PL user
        
        $this->client->request('POST', '/activity/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 99999, // Non-existent activity ID
        ]));
        
        // Should return error for already deleted/non-existent item
        $this->assertStatusCode(422);
        $responseContent = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Already deleted', $responseContent);
    }

    /**
     * Test that unauthenticated requests are properly rejected.
     */
    public function testDeleteActionWithoutAuthentication(): void
    {
        // Don't log in any user - test unauthenticated access
        $this->client = static::createClient(); // Fresh client without authentication
        
        $this->client->request('POST', '/activity/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 1,
        ]));
        
        // Should redirect to login or return 401/403
        $response = $this->client->getResponse();
        $this->assertTrue(
            $response->isRedirection() || 
            $response->getStatusCode() === 401 || 
            $response->getStatusCode() === 403,
            'Unauthenticated requests should be rejected'
        );
    }

    /**
     * Test authorization enforcement across different HTTP methods.
     * Ensure that GET requests to delete endpoints are properly rejected.
     */
    public function testDeleteActionWithWrongHttpMethod(): void
    {
        $this->logInSession('unittest'); // PL user
        
        // Attempt to access delete endpoint with GET method
        $this->expectException(\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class);
        $this->client->request('GET', '/activity/delete');
    }
}
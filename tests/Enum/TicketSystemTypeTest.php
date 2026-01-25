<?php

declare(strict_types=1);

namespace Tests\Enum;

use App\Enum\TicketSystemType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TicketSystemType enum.
 *
 * @internal
 */
#[CoversClass(TicketSystemType::class)]
final class TicketSystemTypeTest extends TestCase
{
    // ==================== Case value tests ====================

    public function testUnknownHasEmptyValue(): void
    {
        self::assertSame('', TicketSystemType::UNKNOWN->value);
    }

    public function testJiraHasCorrectValue(): void
    {
        self::assertSame('JIRA', TicketSystemType::JIRA->value);
    }

    public function testOtrsHasCorrectValue(): void
    {
        self::assertSame('OTRS', TicketSystemType::OTRS->value);
    }

    public function testFreshdeskHasCorrectValue(): void
    {
        self::assertSame('FRESHDESK', TicketSystemType::FRESHDESK->value);
    }

    // ==================== getDisplayName tests ====================

    public function testGetDisplayNameForUnknown(): void
    {
        self::assertSame('Unknown/Not Configured', TicketSystemType::UNKNOWN->getDisplayName());
    }

    public function testGetDisplayNameForJira(): void
    {
        self::assertSame('Atlassian Jira', TicketSystemType::JIRA->getDisplayName());
    }

    public function testGetDisplayNameForOtrs(): void
    {
        self::assertSame('OTRS (Open Ticket Request System)', TicketSystemType::OTRS->getDisplayName());
    }

    public function testGetDisplayNameForFreshdesk(): void
    {
        self::assertSame('Freshdesk', TicketSystemType::FRESHDESK->getDisplayName());
    }

    // ==================== getDefaultUrlPattern tests ====================

    public function testGetDefaultUrlPatternForUnknown(): void
    {
        self::assertSame('', TicketSystemType::UNKNOWN->getDefaultUrlPattern());
    }

    public function testGetDefaultUrlPatternForJira(): void
    {
        $pattern = TicketSystemType::JIRA->getDefaultUrlPattern();

        self::assertStringContainsString('browse', $pattern);
        self::assertStringContainsString('%s', $pattern);
    }

    public function testGetDefaultUrlPatternForOtrs(): void
    {
        $pattern = TicketSystemType::OTRS->getDefaultUrlPattern();

        self::assertStringContainsString('AgentTicketZoom', $pattern);
        self::assertStringContainsString('%s', $pattern);
    }

    public function testGetDefaultUrlPatternForFreshdesk(): void
    {
        $pattern = TicketSystemType::FRESHDESK->getDefaultUrlPattern();

        self::assertStringContainsString('freshdesk.com', $pattern);
        self::assertStringContainsString('%s', $pattern);
    }

    // ==================== getApiEndpointPattern tests ====================

    public function testGetApiEndpointPatternForUnknown(): void
    {
        self::assertSame('', TicketSystemType::UNKNOWN->getApiEndpointPattern());
    }

    public function testGetApiEndpointPatternForJira(): void
    {
        $pattern = TicketSystemType::JIRA->getApiEndpointPattern();

        self::assertStringContainsString('/rest/api', $pattern);
        self::assertStringContainsString('%s', $pattern);
    }

    public function testGetApiEndpointPatternForOtrs(): void
    {
        $pattern = TicketSystemType::OTRS->getApiEndpointPattern();

        self::assertStringContainsString('GenericTicketConnector', $pattern);
        self::assertStringContainsString('%s', $pattern);
    }

    public function testGetApiEndpointPatternForFreshdesk(): void
    {
        $pattern = TicketSystemType::FRESHDESK->getApiEndpointPattern();

        self::assertStringContainsString('/api/v2', $pattern);
        self::assertStringContainsString('%s', $pattern);
    }

    // ==================== supportsOAuth tests ====================

    public function testSupportsOAuthForUnknown(): void
    {
        self::assertFalse(TicketSystemType::UNKNOWN->supportsOAuth());
    }

    public function testSupportsOAuthForJira(): void
    {
        self::assertTrue(TicketSystemType::JIRA->supportsOAuth());
    }

    public function testSupportsOAuthForOtrs(): void
    {
        self::assertFalse(TicketSystemType::OTRS->supportsOAuth());
    }

    public function testSupportsOAuthForFreshdesk(): void
    {
        self::assertFalse(TicketSystemType::FRESHDESK->supportsOAuth());
    }

    // ==================== supportsTimeTracking tests ====================

    public function testSupportsTimeTrackingForUnknown(): void
    {
        self::assertFalse(TicketSystemType::UNKNOWN->supportsTimeTracking());
    }

    public function testSupportsTimeTrackingForJira(): void
    {
        self::assertTrue(TicketSystemType::JIRA->supportsTimeTracking());
    }

    public function testSupportsTimeTrackingForOtrs(): void
    {
        self::assertFalse(TicketSystemType::OTRS->supportsTimeTracking());
    }

    public function testSupportsTimeTrackingForFreshdesk(): void
    {
        self::assertFalse(TicketSystemType::FRESHDESK->supportsTimeTracking());
    }

    // ==================== getRequiredAuthFields tests ====================

    public function testGetRequiredAuthFieldsForUnknown(): void
    {
        self::assertSame([], TicketSystemType::UNKNOWN->getRequiredAuthFields());
    }

    public function testGetRequiredAuthFieldsForJira(): void
    {
        $fields = TicketSystemType::JIRA->getRequiredAuthFields();

        self::assertContains('username', $fields);
        self::assertContains('api_token', $fields);
        self::assertCount(2, $fields);
    }

    public function testGetRequiredAuthFieldsForOtrs(): void
    {
        $fields = TicketSystemType::OTRS->getRequiredAuthFields();

        self::assertContains('username', $fields);
        self::assertContains('password', $fields);
        self::assertCount(2, $fields);
    }

    public function testGetRequiredAuthFieldsForFreshdesk(): void
    {
        $fields = TicketSystemType::FRESHDESK->getRequiredAuthFields();

        self::assertContains('api_key', $fields);
        self::assertCount(1, $fields);
    }

    // ==================== getTicketPattern tests ====================

    public function testGetTicketPatternForUnknown(): void
    {
        $pattern = TicketSystemType::UNKNOWN->getTicketPattern();

        self::assertSame(1, preg_match($pattern, 'anything'));
    }

    public function testGetTicketPatternForJiraMatchesValidTicket(): void
    {
        $pattern = TicketSystemType::JIRA->getTicketPattern();

        self::assertSame(1, preg_match($pattern, 'PROJ-123'));
        self::assertSame(1, preg_match($pattern, 'ABC-1'));
        self::assertSame(1, preg_match($pattern, 'LONGPROJECTKEY-99999'));
    }

    public function testGetTicketPatternForJiraRejectsInvalidTicket(): void
    {
        $pattern = TicketSystemType::JIRA->getTicketPattern();

        self::assertSame(0, preg_match($pattern, 'proj-123')); // lowercase
        self::assertSame(0, preg_match($pattern, 'PROJ123'));  // no dash
        self::assertSame(0, preg_match($pattern, '123-ABC'));  // reversed
    }

    public function testGetTicketPatternForOtrsMatchesValidTicket(): void
    {
        $pattern = TicketSystemType::OTRS->getTicketPattern();

        self::assertSame(1, preg_match($pattern, '1234'));
        self::assertSame(1, preg_match($pattern, '123456789'));
    }

    public function testGetTicketPatternForOtrsRejectsInvalidTicket(): void
    {
        $pattern = TicketSystemType::OTRS->getTicketPattern();

        self::assertSame(0, preg_match($pattern, '123'));  // too short
        self::assertSame(0, preg_match($pattern, 'ABC'));  // letters
    }

    public function testGetTicketPatternForFreshdeskMatchesValidTicket(): void
    {
        $pattern = TicketSystemType::FRESHDESK->getTicketPattern();

        self::assertSame(1, preg_match($pattern, '1'));
        self::assertSame(1, preg_match($pattern, '123456'));
    }

    public function testGetTicketPatternForFreshdeskRejectsInvalidTicket(): void
    {
        $pattern = TicketSystemType::FRESHDESK->getTicketPattern();

        self::assertSame(0, preg_match($pattern, 'ABC'));  // letters
        self::assertSame(0, preg_match($pattern, ''));     // empty
    }

    // ==================== all() tests ====================

    public function testAllReturnsAllCases(): void
    {
        $all = TicketSystemType::all();

        self::assertCount(4, $all);
        self::assertContains(TicketSystemType::UNKNOWN, $all);
        self::assertContains(TicketSystemType::JIRA, $all);
        self::assertContains(TicketSystemType::OTRS, $all);
        self::assertContains(TicketSystemType::FRESHDESK, $all);
    }

    // ==================== withTimeTracking() tests ====================

    public function testWithTimeTrackingReturnsOnlyJira(): void
    {
        $timeTrackingSystems = TicketSystemType::withTimeTracking();

        self::assertCount(1, $timeTrackingSystems);
        self::assertContains(TicketSystemType::JIRA, $timeTrackingSystems);
        self::assertNotContains(TicketSystemType::UNKNOWN, $timeTrackingSystems);
        self::assertNotContains(TicketSystemType::OTRS, $timeTrackingSystems);
        self::assertNotContains(TicketSystemType::FRESHDESK, $timeTrackingSystems);
    }

    // ==================== isConfigured() tests ====================

    public function testIsConfiguredForUnknown(): void
    {
        self::assertFalse(TicketSystemType::UNKNOWN->isConfigured());
    }

    public function testIsConfiguredForJira(): void
    {
        self::assertTrue(TicketSystemType::JIRA->isConfigured());
    }

    public function testIsConfiguredForOtrs(): void
    {
        self::assertTrue(TicketSystemType::OTRS->isConfigured());
    }

    public function testIsConfiguredForFreshdesk(): void
    {
        self::assertTrue(TicketSystemType::FRESHDESK->isConfigured());
    }

    // ==================== Type casting tests ====================

    public function testCanCreateFromString(): void
    {
        self::assertSame(TicketSystemType::JIRA, TicketSystemType::from('JIRA'));
        self::assertSame(TicketSystemType::OTRS, TicketSystemType::from('OTRS'));
        self::assertSame(TicketSystemType::FRESHDESK, TicketSystemType::from('FRESHDESK'));
        self::assertSame(TicketSystemType::UNKNOWN, TicketSystemType::from(''));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(TicketSystemType::tryFrom('INVALID'));
        self::assertNull(TicketSystemType::tryFrom('jira')); // lowercase
    }
}

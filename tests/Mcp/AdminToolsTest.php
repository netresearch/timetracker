<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Mcp;

use App\Mcp\Tool\OnboardCustomerTool;
use App\Mcp\Tool\OnboardProjectTool;
use App\Mcp\Tool\OnboardUserTool;
use App\Mcp\Tool\SetProjectActiveTool;
use App\Mcp\Tool\SetUserActiveTool;
use App\Mcp\Tool\UpdateProjectTool;
use Mcp\Exception\ToolCallException;
use Tests\AbstractWebTestCase;
use Tests\Traits\ActsAsApiTokenUser;

/**
 * The MCP admin tools (ADR-022 Phase 3): on/offboarding flows and the double
 * gate — the scope alone is not enough, the token's user must be an admin.
 * Fixture users: 'unittest' (ADMIN), 'developer' (DEV, non-admin); teams 1+2.
 *
 * @internal
 */
final class AdminToolsTest extends AbstractWebTestCase
{
    use ActsAsApiTokenUser;

    public function testOnboardProjectCreatesActiveProject(): void
    {
        $this->useToken(['projects:write']);

        $result = self::getContainer()->get(OnboardProjectTool::class)->onboardProject(name: 'Rocket Site', customer: '1', ticketPrefix: 'RCK');

        self::assertIsArray($result['project']);
        self::assertSame('Rocket Site', $result['project']['name']);
        self::assertSame('RCK', $result['project']['jira_id']);
        self::assertTrue($result['project']['active']);
        self::assertSame(1, $result['project']['customer_id']);
    }

    public function testOnboardProjectResolvesCustomerByName(): void
    {
        $this->useToken(['projects:write', 'customers:write']);
        $container = self::getContainer();
        $customer = $container->get(OnboardCustomerTool::class)->onboardCustomer(name: 'Resolvable Corp', global: true);
        self::assertIsArray($customer['customer']);

        $result = $container->get(OnboardProjectTool::class)->onboardProject(name: 'Resolved Project', customer: 'Resolvable Corp');

        self::assertIsArray($result['project']);
        self::assertSame($customer['customer']['id'], $result['project']['customer_id']);
    }

    public function testOnboardProjectRejectsUnknownCustomer(): void
    {
        $this->useToken(['projects:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(OnboardProjectTool::class)->onboardProject(name: 'Orphan', customer: 'no-such-customer');
    }

    public function testOnboardProjectAssignsTheTicketSystemById(): void
    {
        $this->useToken(['projects:write']);

        $result = self::getContainer()->get(OnboardProjectTool::class)->onboardProject(name: 'Booked Project', customer: '1', ticketSystem: '1');

        self::assertIsArray($result['project']);
        self::assertSame(1, $result['project']['ticket_system_id']);
        // The fixture system has book_time = 0, so the caller is told that
        // entries on this project still sync nowhere (#688).
        self::assertFalse($result['project']['ticket_system_books_time']);
    }

    public function testOnboardProjectResolvesTheTicketSystemByName(): void
    {
        $this->useToken(['projects:write']);

        $result = self::getContainer()->get(OnboardProjectTool::class)->onboardProject(name: 'Named System Project', customer: '1', ticketSystem: 'testSystem');

        self::assertIsArray($result['project']);
        self::assertSame(1, $result['project']['ticket_system_id']);
    }

    public function testOnboardProjectWithoutATicketSystemReportsNone(): void
    {
        $this->useToken(['projects:write']);

        $result = self::getContainer()->get(OnboardProjectTool::class)->onboardProject(name: 'Unbooked Project', customer: '1');

        self::assertIsArray($result['project']);
        self::assertNull($result['project']['ticket_system_id']);
        self::assertNull($result['project']['ticket_system_books_time']);
    }

    public function testOnboardProjectRejectsUnknownTicketSystem(): void
    {
        $this->useToken(['projects:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(OnboardProjectTool::class)->onboardProject(name: 'Bad System', customer: '1', ticketSystem: 'no-such-system');
    }

    /**
     * The shape #688 reports: a project onboarded without a ticket system books
     * no worklogs, and nothing but the admin UI could repair it.
     */
    public function testUpdateProjectAssignsATicketSystemAfterOnboarding(): void
    {
        $this->useToken(['projects:write']);
        $container = self::getContainer();
        $created = $container->get(OnboardProjectTool::class)->onboardProject(name: 'Repairable Project', customer: '1');
        self::assertIsArray($created['project']);
        self::assertNull($created['project']['ticket_system_id']);

        $projectId = $created['project']['id'];
        self::assertIsInt($projectId);

        $updated = $container->get(UpdateProjectTool::class)->updateProject(project: (string) $projectId, ticketSystem: 'testSystem');

        self::assertIsArray($updated['project']);
        self::assertSame(1, $updated['project']['ticket_system_id']);
        self::assertSame('Repairable Project', $updated['project']['name']);
    }

    public function testUpdateProjectLeavesOmittedFieldsAlone(): void
    {
        $this->useToken(['projects:write']);
        $container = self::getContainer();
        $created = $container->get(OnboardProjectTool::class)->onboardProject(name: 'Untouched Project', customer: '1', ticketPrefix: 'UNT', global: true);
        self::assertIsArray($created['project']);

        $projectId = $created['project']['id'];
        self::assertIsInt($projectId);

        $updated = $container->get(UpdateProjectTool::class)->updateProject(project: (string) $projectId, name: 'Renamed Project');

        self::assertIsArray($updated['project']);
        self::assertSame('Renamed Project', $updated['project']['name']);
        self::assertSame('UNT', $updated['project']['jira_id']);
        self::assertTrue($updated['project']['global']);
        self::assertTrue($updated['project']['active']);
    }

    public function testUpdateProjectRejectsUnknownProject(): void
    {
        $this->useToken(['projects:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(UpdateProjectTool::class)->updateProject(project: 'no-such-project', ticketSystem: '1');
    }

    public function testOnboardCustomerRequiresTeamUnlessGlobal(): void
    {
        $this->useToken(['customers:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(OnboardCustomerTool::class)->onboardCustomer(name: 'Teamless GmbH');
    }

    public function testOnboardUserCreatesDirectoryAccount(): void
    {
        $this->useToken(['users:write']);

        $result = self::getContainer()->get(OnboardUserTool::class)->onboardUser(username: 'new.hire', abbr: 'NHR', teamIds: [1]);

        self::assertIsArray($result['user']);
        self::assertSame('new.hire', $result['user']['username']);
        self::assertSame('DEV', $result['user']['type']);
        self::assertTrue($result['user']['active']);
        self::assertSame([1], $result['user']['team_ids']);
    }

    public function testOnboardUserRejectsDuplicateUsername(): void
    {
        $this->useToken(['users:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(OnboardUserTool::class)->onboardUser(username: 'unittest', abbr: 'UTX', teamIds: [1]);
    }

    public function testOffboardUserDeactivatesTheAccount(): void
    {
        $this->useToken(['users:write']);

        $result = self::getContainer()->get(SetUserActiveTool::class)->setUserActive('developer', false);

        self::assertIsArray($result['user']);
        self::assertFalse($result['user']['active']);
    }

    public function testAdminCannotDeactivateOwnAccountViaTool(): void
    {
        // The token acts as 'unittest' — self-deactivation would lock the
        // admin out.
        $this->useToken(['users:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(SetUserActiveTool::class)->setUserActive('unittest', false);
    }

    public function testOffboardProjectByName(): void
    {
        $this->useToken(['projects:write']);

        $result = self::getContainer()->get(SetProjectActiveTool::class)->setProjectActive('Das Kuchenbacken', false);

        self::assertIsArray($result['project']);
        self::assertFalse($result['project']['active']);
    }

    public function testScopeAloneIsNotEnoughWithoutAdminRole(): void
    {
        // 'developer' is a non-admin: the write scope must not open the tool.
        $this->useToken(['projects:write'], 'developer');

        try {
            self::getContainer()->get(OnboardProjectTool::class)->onboardProject(name: 'Sneaky', customer: '1');
            self::fail('expected the admin gate to refuse');
        } catch (ToolCallException $toolCallException) {
            self::assertStringContainsString('administrator', $toolCallException->getMessage());
        }
    }

    public function testAdminWithoutScopeIsDenied(): void
    {
        // 'unittest' is an admin, but the token lacks the write scope.
        $this->useToken(['projects:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(OnboardProjectTool::class)->onboardProject(name: 'Unscoped', customer: '1');
    }
}

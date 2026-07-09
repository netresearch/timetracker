<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Mcp;

use App\Mcp\Tool\BulkLogTimeTool;
use App\Mcp\Tool\ListCustomersTool;
use App\Mcp\Tool\ListUsersTool;
use App\Mcp\Tool\LogTimeTool;
use App\Mcp\Tool\SaveTeamTool;
use App\Mcp\Tool\UpdateEntryTool;
use Mcp\Exception\ToolCallException;
use Tests\AbstractWebTestCase;
use Tests\Traits\ActsAsApiTokenUser;

/**
 * The Phase-4 write/list/manage MCP tools (ADR-022): edit, bulk, list and the
 * team/contract/ticketsystem management. Fixture user 1 = 'unittest' (ADMIN);
 * project/activity/customer/preset id 1 and teams 1-2 exist.
 *
 * @internal
 */
final class EditBulkAdminToolsTest extends AbstractWebTestCase
{
    use ActsAsApiTokenUser;

    public function testUpdateEntryChangesOnlyTheGivenField(): void
    {
        $this->useToken(['entries:write']);
        $container = self::getContainer();
        $created = $container->get(LogTimeTool::class)->logTime(project: '1', activity: '1', ticket: 'SA-1', durationMinutes: 60, description: 'original');
        self::assertIsArray($created['result'] ?? null);
        $id = $created['result']['id'];
        self::assertIsInt($id);

        $result = $container->get(UpdateEntryTool::class)->updateEntry(entryId: $id, description: 'changed');

        self::assertIsArray($result['result']);
        self::assertSame('changed', $result['result']['description']);
        // Ticket and duration were not passed → kept.
        self::assertSame('SA-1', $result['result']['ticket']);
        self::assertSame('01:00', $result['result']['duration']);
    }

    public function testUpdateEntryRejectsAnotherUsersEntry(): void
    {
        // Create an entry owned by 'developer', then try to edit it as 'unittest'.
        $this->useToken(['entries:write'], 'developer');
        $foreign = self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', durationMinutes: 30);
        self::assertIsArray($foreign['result'] ?? null);
        $foreignId = $foreign['result']['id'];
        self::assertIsInt($foreignId);

        $this->useToken(['entries:write']); // back to unittest
        $this->expectException(ToolCallException::class);
        self::getContainer()->get(UpdateEntryTool::class)->updateEntry(entryId: $foreignId, description: 'hijack');
    }

    public function testUpdateEntryIsDeniedWithoutScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(UpdateEntryTool::class)->updateEntry(entryId: 1, description: 'x');
    }

    public function testBulkLogTimeFillsARangeFromAPreset(): void
    {
        $this->useToken(['entries:write']);

        $result = self::getContainer()->get(BulkLogTimeTool::class)->bulkLogTime(
            preset: 'Urlaub',
            startDate: '2026-07-06',
            endDate: '2026-07-08',
            useContract: false,
            skipWeekend: false,
            skipHolidays: false,
            startTime: '09:00',
            endTime: '17:00',
        );

        self::assertTrue($result['success']);
        self::assertNotSame('', $result['message']);
    }

    public function testBulkLogTimeRejectsUnknownPreset(): void
    {
        $this->useToken(['entries:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(BulkLogTimeTool::class)->bulkLogTime(preset: 'no-such-preset', startDate: '2026-07-06', endDate: '2026-07-06');
    }

    public function testListCustomersReturnsFixtureCustomer(): void
    {
        $this->useToken(['customers:read']);

        $result = self::getContainer()->get(ListCustomersTool::class)->listCustomers();

        self::assertNotEmpty($result['customers']);
        self::assertArrayHasKey('active', $result['customers'][0]);
    }

    public function testListUsersRequiresAdmin(): void
    {
        // 'developer' holds users:read via the wildcard-free token but is not an admin.
        $this->useToken(['users:read'], 'developer');

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(ListUsersTool::class)->listUsers();
    }

    public function testSaveTeamCreatesAndUpdates(): void
    {
        $this->useToken(['teams:write']);
        $container = self::getContainer();

        $created = $container->get(SaveTeamTool::class)->saveTeam(name: 'QA Crew', leadUser: 'unittest');
        self::assertIsArray($created['team']);
        $id = $created['team'][0];
        self::assertIsInt($id);

        $updated = $container->get(SaveTeamTool::class)->saveTeam(name: 'QA Crew Renamed', leadUser: 'unittest', teamId: $id);
        self::assertIsArray($updated['team']);
        self::assertSame($id, $updated['team'][0]);
    }

    public function testSaveTeamNonAdminWithScopeIsDenied(): void
    {
        // The write scope alone must not open an admin tool for a non-admin.
        $this->useToken(['teams:write'], 'developer');

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(SaveTeamTool::class)->saveTeam(name: 'Sneaky', leadUser: 'developer');
    }
}

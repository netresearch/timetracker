<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Mcp;

use App\Entity\User;
use App\Mcp\Tool\DeleteEntryTool;
use App\Mcp\Tool\GetTicketInfoTool;
use App\Mcp\Tool\GetTimeBalanceTool;
use App\Mcp\Tool\ListActivitiesTool;
use App\Mcp\Tool\ListProjectsTool;
use App\Mcp\Tool\ListRecentEntriesTool;
use App\Mcp\Tool\LogTimeTool;
use App\Repository\UserRepository;
use App\Security\ApiToken\ApiAccessToken;
use Mcp\Exception\ToolCallException;
use Tests\AbstractWebTestCase;

use function array_column;
use function array_values;

/**
 * Integration tests for the MCP tools (ADR-021 Phase 5), exercised through the
 * real container with a scoped ApiAccessToken — the same path the /mcp endpoint
 * takes. Fixture user 1 = 'unittest'; project/customer/activity id 1 exist;
 * project 1's jira_id is 'SA'.
 */
final class McpToolsTest extends AbstractWebTestCase
{
    public function testListActivitiesReturnsEntriesWithReadScope(): void
    {
        $this->useToken(['activities:read']);

        $result = self::getContainer()->get(ListActivitiesTool::class)->listActivities();

        self::assertNotEmpty($result);
        self::assertArrayHasKey('id', $result[0]);
        self::assertArrayHasKey('name', $result[0]);
        self::assertArrayHasKey('needs_ticket', $result[0]);
    }

    public function testListActivitiesIsDeniedWithoutScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(ListActivitiesTool::class)->listActivities();
    }

    public function testListActivitiesIsDeniedForSessionAuth(): void
    {
        // setUp() logs in a session; without an ApiAccessToken the tool refuses.
        $this->expectException(ToolCallException::class);
        self::getContainer()->get(ListActivitiesTool::class)->listActivities();
    }

    public function testListProjectsReturnsBookableProjects(): void
    {
        $this->useToken(['projects:read']);

        $result = self::getContainer()->get(ListProjectsTool::class)->listProjects();

        self::assertNotEmpty($result);
        self::assertContains(1, array_column($result, 'id'));
    }

    public function testListProjectsIsDeniedWithoutScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(ListProjectsTool::class)->listProjects();
    }

    public function testListRecentEntriesReturnsArray(): void
    {
        $this->useToken(['entries:read']);

        $result = self::getContainer()->get(ListRecentEntriesTool::class)->listRecentEntries(30);

        self::assertIsList($result);
    }

    public function testListRecentEntriesIsDeniedWithoutScope(): void
    {
        $this->useToken(['projects:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(ListRecentEntriesTool::class)->listRecentEntries();
    }

    public function testLogTimeCreatesEntry(): void
    {
        $this->useToken(['entries:write']);

        $result = self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
            ticket: 'SA-123',
            durationMinutes: 60,
            description: 'logged via MCP',
        );

        self::assertArrayHasKey('result', $result);
        self::assertIsArray($result['result']);
        self::assertArrayHasKey('id', $result['result']);
    }

    public function testLogTimeIsDeniedWithoutScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
            durationMinutes: 60,
        );
    }

    public function testLogTimeRejectsUnknownProject(): void
    {
        $this->useToken(['entries:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: 'no-such-project-xyz',
            activity: '1',
            durationMinutes: 60,
        );
    }

    public function testLogTimeRequiresDurationOrTimes(): void
    {
        $this->useToken(['entries:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
        );
    }

    public function testLogTimeRejectsDurationPastMidnight(): void
    {
        $this->useToken(['entries:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
            durationMinutes: 120,
            start: '23:00',
        );
    }

    public function testLogTimeRejectsMalformedStart(): void
    {
        $this->useToken(['entries:write']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
            durationMinutes: 60,
            start: 'not-a-time',
        );
    }

    public function testDeleteEntryRemovesOwnEntry(): void
    {
        $this->useToken(['entries:write']);
        $logTime = self::getContainer()->get(LogTimeTool::class);
        $created = $logTime->logTime(project: '1', activity: '1', ticket: 'SA-1', durationMinutes: 30);
        self::assertIsArray($created['result'] ?? null);
        $id = $created['result']['id'];
        self::assertIsInt($id);

        $result = self::getContainer()->get(DeleteEntryTool::class)->deleteEntry($id);

        self::assertSame(['success' => true], $result);
    }

    public function testDeleteEntryIsDeniedWithoutScope(): void
    {
        $this->useToken(['reporting:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(DeleteEntryTool::class)->deleteEntry(1);
    }

    public function testGetTimeBalanceReturnsPeriods(): void
    {
        $this->useToken(['reporting:read']);

        $result = self::getContainer()->get(GetTimeBalanceTool::class)->getTimeBalance();

        self::assertArrayHasKey('warnings', $result);
        self::assertIsList($result['warnings']);
        foreach (['today', 'week', 'month'] as $period) {
            self::assertArrayHasKey($period, $result);
            self::assertIsArray($result[$period]);
            foreach (['ist', 'soll_total', 'soll_so_far', 'diff', 'status'] as $key) {
                self::assertArrayHasKey($key, $result[$period]);
            }
        }
    }

    public function testGetTimeBalanceIsDeniedWithoutScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(GetTimeBalanceTool::class)->getTimeBalance();
    }

    public function testGetTicketInfoReturnsScopes(): void
    {
        // Create an entry to report on.
        $this->useToken(['entries:write']);
        $created = self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', ticket: 'SA-9', durationMinutes: 30);
        self::assertIsArray($created['result'] ?? null);
        $id = $created['result']['id'];
        self::assertIsInt($id);

        $this->useToken(['reporting:read']);
        $info = self::getContainer()->get(GetTicketInfoTool::class)->getTicketInfo($id);

        foreach (['customer', 'project', 'activity', 'ticket', 'estimate', 'warnings'] as $key) {
            self::assertArrayHasKey($key, $info);
        }
        self::assertIsArray($info['estimate']);
        self::assertArrayHasKey('status', $info['estimate']);
    }

    public function testGetTicketInfoRejectsUnknownEntry(): void
    {
        $this->useToken(['reporting:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(GetTicketInfoTool::class)->getTicketInfo(999999);
    }

    public function testGetTicketInfoIsDeniedWithoutScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(GetTicketInfoTool::class)->getTicketInfo(1);
    }

    public function testLogTimeReturnsTicketInfoAndBalance(): void
    {
        $this->useToken(['entries:write']);

        $result = self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', ticket: 'SA-8', durationMinutes: 45);

        self::assertArrayHasKey('ticket_info', $result);
        self::assertArrayHasKey('balance', $result);
        self::assertIsArray($result['balance']);
        self::assertArrayHasKey('today', $result['balance']);
    }

    /**
     * Replace the session token from setUp() with a stateless PAT carrying the
     * given scopes, acting as fixture user 1.
     *
     * @param list<string> $scopes
     */
    private function useToken(array $scopes): void
    {
        $container = self::getContainer();
        $user = $container->get(UserRepository::class)->find(1);
        self::assertInstanceOf(User::class, $user);

        $token = new ApiAccessToken($user, array_values($user->getRoles()), $scopes);
        $container->get('security.token_storage')->setToken($token);
    }
}

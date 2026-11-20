<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Mcp\Tool;

use App\Mcp\Tool\LogTimeTool;
use App\Mcp\Tool\SummarizeTimeTool;
use Mcp\Exception\ToolCallException;
use Tests\AbstractWebTestCase;
use Tests\Traits\ActsAsApiTokenUser;

use function array_column;

/**
 * @internal
 *
 * @coversNothing
 */
final class SummarizeTimeToolTest extends AbstractWebTestCase
{
    use ActsAsApiTokenUser;

    private const string DAY = '2024-06-03';

    public function testGroupsByProjectKeepingHumanAndAgentMinutesApart(): void
    {
        $this->useToken(['entries:write', 'reporting:read']);
        $logTime = self::getContainer()->get(LogTimeTool::class);
        $logTime->logTime(project: '1', activity: '1', start: '08:00', end: '09:00', date: self::DAY);
        $logTime->logTime(project: '1', activity: '1', ticket: 'SA-1', date: self::DAY, start: '10:00', agentWalltimeMinutes: 120, humanMinutes: 30);

        $result = self::getContainer()->get(SummarizeTimeTool::class)->summarizeTime(from: self::DAY, to: self::DAY);

        self::assertSame(['humanMinutes' => 90, 'agentMinutes' => 120, 'entries' => 3], $result['totals']);
        self::assertIsArray($result['groups']);
        self::assertCount(1, $result['groups']);
        self::assertIsArray($result['groups'][0]);
        self::assertSame(1, $result['groups'][0]['key']);
        self::assertSame(90, $result['groups'][0]['humanMinutes']);
        self::assertSame(120, $result['groups'][0]['agentMinutes']);
        self::assertSame(100.0, $result['groups'][0]['share']);
    }

    public function testGroupsByTicket(): void
    {
        $this->useToken(['entries:write', 'reporting:read']);
        $logTime = self::getContainer()->get(LogTimeTool::class);
        $logTime->logTime(project: '1', activity: '1', ticket: 'SA-1', start: '08:00', end: '09:00', date: self::DAY);
        $logTime->logTime(project: '1', activity: '1', start: '09:00', end: '09:30', date: self::DAY);

        $result = self::getContainer()->get(SummarizeTimeTool::class)->summarizeTime(from: self::DAY, to: self::DAY, groupBy: 'ticket');

        self::assertIsArray($result['groups']);
        self::assertSame(['SA-1', '(no ticket)'], array_column($result['groups'], 'name'));
        self::assertSame([60, 30], array_column($result['groups'], 'humanMinutes'));
    }

    public function testDevAccountSeesOnlyItsOwnEntries(): void
    {
        // user 1 (unittest, ADMIN) and user 2 (developer, DEV) both book.
        $this->useToken(['entries:write', 'reporting:read']);
        self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', start: '08:00', end: '09:00', date: self::DAY);
        $this->useToken(['entries:write', 'reporting:read'], 'developer');
        self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', start: '10:00', end: '10:20', date: self::DAY);

        $tool = self::getContainer()->get(SummarizeTimeTool::class);
        $everyone = $tool->summarizeTime(from: self::DAY, to: self::DAY, allUsers: true);

        self::assertSame(['humanMinutes' => 20, 'agentMinutes' => 0, 'entries' => 1], $everyone['totals']);
        self::assertSame(['user' => 2], $everyone['scope']);
    }

    public function testDevAccountCannotAskForAnotherUser(): void
    {
        $this->useToken(['reporting:read'], 'developer');

        $this->assertToolError('only summarize its own time', static fn () => self::getContainer()->get(SummarizeTimeTool::class)->summarizeTime(user: 1));
    }

    public function testAFilterKeepsTheOwnTimeDefaultUnlessAllUsersIsAsked(): void
    {
        $this->useToken(['entries:write', 'reporting:read'], 'i.myself');
        self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', start: '08:00', end: '09:00', date: self::DAY);
        $this->useToken(['entries:write', 'reporting:read']);
        self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', start: '10:00', end: '10:30', date: self::DAY);

        $tool = self::getContainer()->get(SummarizeTimeTool::class);
        $mine = $tool->summarizeTime(from: self::DAY, to: self::DAY, project: 1);
        $all = $tool->summarizeTime(from: self::DAY, to: self::DAY, project: 1, allUsers: true);

        self::assertIsArray($mine['totals']);
        self::assertSame(30, $mine['totals']['humanMinutes']);
        self::assertSame(['user' => 1], $mine['scope']);
        self::assertIsArray($all['totals']);
        self::assertSame(90, $all['totals']['humanMinutes']);
        self::assertSame(['user' => null], $all['scope']);
    }

    public function testTicketFilterMatchesExactly(): void
    {
        $this->useToken(['entries:write', 'reporting:read']);
        $logTime = self::getContainer()->get(LogTimeTool::class);
        $logTime->logTime(project: '1', activity: '1', ticket: 'SA-12', start: '08:00', end: '09:00', date: self::DAY);
        $logTime->logTime(project: '1', activity: '1', ticket: 'SA-123', start: '09:00', end: '09:30', date: self::DAY);

        $result = self::getContainer()->get(SummarizeTimeTool::class)->summarizeTime(from: self::DAY, to: self::DAY, ticket: 'sa-12');

        self::assertIsArray($result['totals']);
        self::assertSame(60, $result['totals']['humanMinutes']);
    }

    public function testWithoutFiltersCoversOnlyTheCallersOwnTime(): void
    {
        $this->useToken(['entries:write', 'reporting:read'], 'i.myself');
        self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', start: '08:00', end: '09:00', date: self::DAY);

        $this->useToken(['reporting:read']);
        $result = self::getContainer()->get(SummarizeTimeTool::class)->summarizeTime(from: self::DAY, to: self::DAY);

        self::assertIsArray($result['totals']);
        self::assertSame(0, $result['totals']['entries']);
    }

    public function testRejectsAnUnknownGrouping(): void
    {
        $this->useToken(['reporting:read']);

        $this->assertToolError('Unknown groupBy "week"', static fn () => self::getContainer()->get(SummarizeTimeTool::class)->summarizeTime(groupBy: 'week'));
    }

    public function testRejectsAnImpossibleDate(): void
    {
        $this->useToken(['reporting:read']);

        $this->assertToolError('Invalid from date "2024-02-30"', static fn () => self::getContainer()->get(SummarizeTimeTool::class)->summarizeTime(from: '2024-02-30'));
    }

    public function testRejectsARangeOverAYear(): void
    {
        $this->useToken(['reporting:read']);

        $this->assertToolError('more than 366 days', static fn () => self::getContainer()->get(SummarizeTimeTool::class)->summarizeTime(from: '2023-01-01', to: '2024-06-01'));
    }

    public function testIsDeniedWithoutTheReportingScope(): void
    {
        $this->useToken(['entries:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(SummarizeTimeTool::class)->summarizeTime();
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertToolError(string $expected, callable $call): void
    {
        try {
            $call();
        } catch (ToolCallException $toolCallException) {
            self::assertStringContainsString($expected, $toolCallException->getMessage());

            return;
        }

        self::fail('Expected a ToolCallException.');
    }
}

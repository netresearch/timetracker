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
        // user 1 (unittest, ADMIN) books; user 2 (developer, DEV) asks for user 1.
        $this->useToken(['entries:write', 'reporting:read']);
        self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', start: '08:00', end: '09:00', date: self::DAY);

        $this->useToken(['reporting:read'], 'developer');
        $result = self::getContainer()->get(SummarizeTimeTool::class)->summarizeTime(from: self::DAY, to: self::DAY, user: 1);

        self::assertSame(['humanMinutes' => 0, 'agentMinutes' => 0, 'entries' => 0], $result['totals']);
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

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Mcp\Tool;

use App\Mcp\Tool\ListRecentEntriesTool;
use App\Mcp\Tool\LogTimeTool;
use App\Service\ClockInterface;
use Mcp\Exception\ToolCallException;
use Tests\AbstractWebTestCase;
use Tests\Traits\ActsAsApiTokenUser;

use function array_column;
use function array_values;

/**
 * @internal
 *
 * @coversNothing
 */
final class ListRecentEntriesSourceTest extends AbstractWebTestCase
{
    use ActsAsApiTokenUser;

    public function testSourceFilterSplitsAnAgentPair(): void
    {
        $this->useToken(['entries:write', 'entries:read']);
        // The repository's window follows the frozen test clock.
        $today = self::getContainer()->get(ClockInterface::class)->today()->format('Y-m-d');
        self::getContainer()->get(LogTimeTool::class)->logTime(project: '1', activity: '1', ticket: 'SA-77', date: $today, start: '06:00', agentWalltimeMinutes: 40, humanMinutes: 15);

        $tool = self::getContainer()->get(ListRecentEntriesTool::class);
        $human = $this->paired($tool->listRecentEntries(1, 'human')['entries']);
        $agent = $this->paired($tool->listRecentEntries(1, 'agent')['entries']);

        self::assertSame(['human'], $human['sources']);
        self::assertSame(['agent'], $agent['sources']);
        self::assertContains(15, $human['minutes']);
        self::assertNotContains(40, $human['minutes']);
        self::assertContains(40, $agent['minutes']);
    }

    public function testUnknownSourceIsRefused(): void
    {
        $this->useToken(['entries:read']);

        try {
            self::getContainer()->get(ListRecentEntriesTool::class)->listRecentEntries(1, 'robot');
        } catch (ToolCallException $toolCallException) {
            self::assertStringContainsString('Unknown source "robot"', $toolCallException->getMessage());

            return;
        }

        self::fail('Expected a ToolCallException.');
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array{sources: list<string>, minutes: list<mixed>}
     */
    private function paired(array $entries): array
    {
        self::assertNotSame([], $entries);

        $sources = [];
        foreach ($entries as $entry) {
            self::assertIsString($entry['source'] ?? null);
            $sources[$entry['source']] = $entry['source'];
        }

        return [
            'sources' => array_values($sources),
            'minutes' => array_column($entries, 'durationMinutes'),
        ];
    }
}

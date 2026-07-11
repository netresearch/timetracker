<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Mcp\Tool;

use App\Entity\Entry;
use App\Entity\User;
use App\Enum\EntrySource;
use App\Mcp\Tool\LogTimeTool;
use App\Repository\EntryRepository;
use Tests\AbstractWebTestCase;
use Tests\Traits\ActsAsApiTokenUser;

use function assert;

/**
 * ADR-025 Task 7: under its PAT the agent dual-writes BOTH streams — a
 * source=agent wall-clock entry and a delegated source=human, estimated=true
 * entry — with the token owner as the responsible user (derived in
 * SaveEntryAction, never passed by the client). Without the agent args the tool
 * keeps its single human write.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogTimeToolSourceTest extends AbstractWebTestCase
{
    use ActsAsApiTokenUser;

    /**
     * @return list<Entry>
     */
    private function entriesForDay(string $day): array
    {
        $manager = self::getContainer()->get('doctrine')->getManager();
        $manager->clear();
        $repository = $manager->getRepository(Entry::class);
        assert($repository instanceof EntryRepository);

        /** @var list<Entry> $entries */
        $entries = $repository->findBy(['user' => 1, 'day' => $day]);

        return $entries;
    }

    public function testDualWritePersistsAgentAndDelegatedHumanEntries(): void
    {
        $this->useToken(['entries:write', 'reporting:read']);

        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
            ticket: 'SA-100',
            date: '2024-05-20',
            description: 'agent session',
            agentWalltimeMinutes: 120,
            humanMinutes: 45,
            touchpoints: ['prompts' => 7, 'reviews' => 2],
        );

        $entries = $this->entriesForDay('2024-05-20');
        self::assertCount(2, $entries);

        $bySource = [];
        foreach ($entries as $entry) {
            $bySource[$entry->getSource()->value] = $entry;
        }

        self::assertArrayHasKey('agent', $bySource);
        self::assertArrayHasKey('human', $bySource);

        $agent = $bySource['agent'];
        self::assertSame(120, $agent->getDuration());
        self::assertFalse($agent->isEstimated());
        self::assertInstanceOf(User::class, $agent->getResponsibleUser());
        self::assertSame(1, $agent->getResponsibleUser()->getId());

        $human = $bySource['human'];
        self::assertSame(45, $human->getDuration());
        self::assertTrue($human->isEstimated());
        self::assertSame(['prompts' => 7, 'reviews' => 2], $human->getTouchpoints());
        self::assertInstanceOf(User::class, $human->getResponsibleUser());
        self::assertSame(1, $human->getResponsibleUser()->getId());
    }

    public function testSingleHumanWriteWithoutAgentArgs(): void
    {
        $this->useToken(['entries:write', 'reporting:read']);

        self::getContainer()->get(LogTimeTool::class)->logTime(
            project: '1',
            activity: '1',
            ticket: 'SA-101',
            durationMinutes: 60,
            date: '2024-05-21',
            description: 'plain human log',
        );

        $entries = $this->entriesForDay('2024-05-21');
        self::assertCount(1, $entries);
        self::assertSame(EntrySource::HUMAN, $entries[0]->getSource());
        self::assertFalse($entries[0]->isEstimated());
        self::assertNull($entries[0]->getTouchpoints());
    }
}

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
use Doctrine\DBAL\Connection;
use Mcp\Exception\ToolCallException;
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

        /** @var list<Entry> */
        return $repository->findBy(['user' => 1, 'day' => $day]);
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

    public function testDualWriteRollsBackAgentEntryWhenHumanWriteFails(): void
    {
        $this->useToken(['entries:write', 'reporting:read']);

        // start=23:00: the agent 30-min write fits (23:30) and persists, but the
        // human 180-min write overruns midnight and throws AFTER it. The pair must
        // be atomic, so the already-persisted agent entry has to roll back too.
        try {
            self::getContainer()->get(LogTimeTool::class)->logTime(
                project: '1',
                activity: '1',
                ticket: 'SA-200',
                start: '23:00',
                date: '2024-05-22',
                description: 'atomic dual-write',
                agentWalltimeMinutes: 30,
                humanMinutes: 180,
            );
            self::fail('expected the overrunning human write to raise a ToolCallException');
        } catch (ToolCallException) {
            // expected: the human write overruns midnight and fails
        }

        // Count via the shared DBAL connection the test harness holds: wrapInTransaction
        // rolls the failed dual-write back to a savepoint inside the test's own outer
        // transaction, so the already-persisted agent entry must be gone. (Querying a
        // reset EntityManager would open a fresh view blind to this transaction.)
        self::assertInstanceOf(Connection::class, $this->connection);
        $orphans = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM entries WHERE user_id = 1 AND day = '2024-05-22'",
        );
        self::assertEquals(0, $orphans, 'the agent entry must roll back with the failed human write — no orphan');
    }

    public function testRejectsNonPositiveAgentWalltime(): void
    {
        $this->useToken(['entries:write', 'reporting:read']);

        try {
            self::getContainer()->get(LogTimeTool::class)->logTime(
                project: '1',
                activity: '1',
                date: '2024-05-23',
                agentWalltimeMinutes: 0,
                humanMinutes: 45,
            );
            self::fail('expected a ToolCallException for non-positive agentWalltimeMinutes');
        } catch (ToolCallException $toolCallException) {
            self::assertStringContainsString('agentWalltimeMinutes', $toolCallException->getMessage());
        }
    }

    public function testRejectsNonPositiveHumanMinutes(): void
    {
        $this->useToken(['entries:write', 'reporting:read']);

        try {
            self::getContainer()->get(LogTimeTool::class)->logTime(
                project: '1',
                activity: '1',
                date: '2024-05-24',
                agentWalltimeMinutes: 120,
                humanMinutes: 0,
            );
            self::fail('expected a ToolCallException for non-positive humanMinutes');
        } catch (ToolCallException $toolCallException) {
            self::assertStringContainsString('humanMinutes', $toolCallException->getMessage());
        }
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

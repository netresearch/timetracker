<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Enum\WorklogField;
use App\Service\Sync\EntryPullApplier;
use App\Service\Sync\TicketProjectResolver;
use App\ValueObject\Sync\ProjectResolution;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function mb_strlen;

#[CoversClass(EntryPullApplier::class)]
#[AllowMockObjectsWithoutExpectations]
final class EntryPullApplierTest extends TestCase
{
    private TicketProjectResolver&MockObject $ticketProjectResolver;

    private EntryPullApplier $entryPullApplier;

    protected function setUp(): void
    {
        $this->ticketProjectResolver = $this->createMock(TicketProjectResolver::class);
        $this->entryPullApplier = new EntryPullApplier($this->ticketProjectResolver);
    }

    private function entry(): Entry
    {
        return new Entry()
            ->setDay('2026-06-15')
            ->setStart('09:00:00')
            ->setEnd('10:00:00')
            ->setDuration(60)
            ->setTicket('ABC-1')
            ->setDescription('old');
    }

    private function ticketSystem(): TicketSystem
    {
        return self::createStub(TicketSystem::class);
    }

    private function snapshot(
        string $issueKey = 'ABC-1',
        ?int $startedTimestamp = null,
        int $durationMinutes = 60,
        string $comment = 'old',
    ): WorklogSnapshot {
        return new WorklogSnapshot(
            $issueKey,
            $startedTimestamp ?? new DateTime('2026-06-15 09:00:00')->getTimestamp(),
            $durationMinutes,
            $comment,
        );
    }

    public function testCommentPullSetsDecodedDescriptionVerbatim(): void
    {
        // The snapshot the applier receives is already decoded (RemoteWorklogNormalizer);
        // the applier writes it into the description without re-decoding.
        $this->ticketProjectResolver->expects(self::never())->method('resolve');

        $entry = $this->entry();
        $remote = $this->snapshot(comment: 'new text');

        $pullResult = $this->entryPullApplier->apply($entry, $remote, [WorklogField::COMMENT], $this->ticketSystem());

        self::assertTrue($pullResult->applied);
        self::assertSame('new text', $entry->getDescription());
        self::assertSame('2026-06-15', $entry->getDay()->format('Y-m-d'));
        self::assertSame('09:00:00', $entry->getStart()->format('H:i:s'));
        self::assertSame(['2026-06-15'], $pullResult->affectedDays);
    }

    public function testCommentPullTruncatesToColumnLimit(): void
    {
        $this->ticketProjectResolver->expects(self::never())->method('resolve');

        $entry = $this->entry();
        $pullResult = $this->entryPullApplier->apply($entry, $this->snapshot(comment: str_repeat('y', 400)), [WorklogField::COMMENT], $this->ticketSystem());

        self::assertTrue($pullResult->applied);
        self::assertSame(255, mb_strlen($entry->getDescription()));
    }

    public function testStartedPullMovesDayAndReportsBothDays(): void
    {
        $this->ticketProjectResolver->expects(self::never())->method('resolve');

        $entry = $this->entry();
        $remote = $this->snapshot(startedTimestamp: new DateTime('2026-06-16 08:00:00')->getTimestamp());

        $pullResult = $this->entryPullApplier->apply($entry, $remote, [WorklogField::STARTED], $this->ticketSystem());

        self::assertTrue($pullResult->applied);
        self::assertSame('2026-06-16', $entry->getDay()->format('Y-m-d'));
        self::assertSame('08:00:00', $entry->getStart()->format('H:i:s'));
        self::assertSame('09:00:00', $entry->getEnd()->format('H:i:s'));
        self::assertSame(60, $entry->getDuration());
        self::assertContains('2026-06-15', $pullResult->affectedDays);
        self::assertContains('2026-06-16', $pullResult->affectedDays);
    }

    public function testDurationPullExtendsEnd(): void
    {
        $this->ticketProjectResolver->expects(self::never())->method('resolve');

        $entry = $this->entry();
        $remote = $this->snapshot(durationMinutes: 90);

        $pullResult = $this->entryPullApplier->apply($entry, $remote, [WorklogField::DURATION], $this->ticketSystem());

        self::assertTrue($pullResult->applied);
        self::assertSame(90, $entry->getDuration());
        self::assertSame('2026-06-15', $entry->getDay()->format('Y-m-d'));
        self::assertSame('09:00:00', $entry->getStart()->format('H:i:s'));
        self::assertSame('10:30:00', $entry->getEnd()->format('H:i:s'));
        self::assertSame(['2026-06-15'], $pullResult->affectedDays);
    }

    public function testIssueKeyPullRemapsProject(): void
    {
        $customer = self::createStub(Customer::class);
        $project = self::createStub(Project::class);
        $project->method('getCustomer')->willReturn($customer);

        $ticketSystem = $this->ticketSystem();
        $this->ticketProjectResolver->expects(self::once())
            ->method('resolve')
            ->with('DEF-9', $ticketSystem)
            ->willReturn(new ProjectResolution($project, 'jira_id prefix match'));

        $entry = $this->entry();
        $remote = $this->snapshot(issueKey: 'DEF-9');

        $pullResult = $this->entryPullApplier->apply($entry, $remote, [WorklogField::ISSUE_KEY], $ticketSystem);

        self::assertTrue($pullResult->applied);
        self::assertSame('DEF-9', $entry->getTicket());
        self::assertSame($project, $entry->getProject());
        self::assertSame($customer, $entry->getCustomer());
    }

    public function testIssueKeyPullFailsWhenUnresolved(): void
    {
        $ticketSystem = $this->ticketSystem();
        $this->ticketProjectResolver->expects(self::once())
            ->method('resolve')
            ->with('DEF-9', $ticketSystem)
            ->willReturn(new ProjectResolution(null, 'no project for prefix DEF on this ticket system'));

        $entry = $this->entry();
        $remote = $this->snapshot(issueKey: 'DEF-9');

        $pullResult = $this->entryPullApplier->apply($entry, $remote, [WorklogField::ISSUE_KEY], $ticketSystem);

        self::assertFalse($pullResult->applied);
        self::assertSame('target project unresolved: no project for prefix DEF on this ticket system', $pullResult->reason);
        self::assertSame('ABC-1', $entry->getTicket());
    }

    public function testMidnightCrossingFails(): void
    {
        $this->ticketProjectResolver->expects(self::never())->method('resolve');

        $entry = $this->entry();
        $remote = $this->snapshot(startedTimestamp: new DateTime('2026-06-16 23:30:00')->getTimestamp());

        $pullResult = $this->entryPullApplier->apply($entry, $remote, [WorklogField::STARTED], $this->ticketSystem());

        self::assertFalse($pullResult->applied);
        self::assertSame('worklog crosses midnight', $pullResult->reason);
        self::assertSame('2026-06-15', $entry->getDay()->format('Y-m-d'));
        self::assertSame('09:00:00', $entry->getStart()->format('H:i:s'));
        self::assertSame('10:00:00', $entry->getEnd()->format('H:i:s'));
        self::assertSame(60, $entry->getDuration());
    }
}

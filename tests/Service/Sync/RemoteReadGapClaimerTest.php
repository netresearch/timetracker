<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Repository\EntryRepository;
use App\Service\Sync\RemoteReadGapClaimer;
use App\Service\Sync\RemoteReadGaps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which unreadable worklogs local entries account for. The assertions are on
 * the gaps object the claimer writes into, not on the repository it reads.
 *
 * @internal
 */
#[CoversClass(RemoteReadGapClaimer::class)]
final class RemoteReadGapClaimerTest extends TestCase
{
    /**
     * @param array<int, Entry> $byWorklogId what the repository finds for the ids it is asked about
     */
    private function claimer(array $byWorklogId = []): RemoteReadGapClaimer
    {
        $entryRepository = self::createStub(EntryRepository::class);
        $entryRepository->method('findByWorklogIdsAndTicketSystem')->willReturn($byWorklogId);

        return new RemoteReadGapClaimer($entryRepository);
    }

    private function entryWithWorklogId(?int $worklogId): Entry
    {
        $entry = new Entry();
        if (null !== $worklogId) {
            $entry->setWorklogId($worklogId);
        }

        return $entry;
    }

    public function testACandidateEntryClaimsItsOwnWorklog(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('error', 42);

        $this->claimer()->claimOwned($remoteReadGaps, [$this->entryWithWorklogId(42)], new TicketSystem());

        self::assertSame([], $remoteReadGaps->unclaimedUnreadableWorklogIds());
        self::assertFalse($remoteReadGaps->hidesMoves());
    }

    public function testAnEntryWithoutAWorklogIdClaimsNothing(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('error', 42);

        $this->claimer()->claimOwned($remoteReadGaps, [$this->entryWithWorklogId(null)], new TicketSystem());

        self::assertSame([42], $remoteReadGaps->unclaimedUnreadableWorklogIds());
    }

    public function testAZeroWorklogIdIsNoClaim(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('error', 42);

        $this->claimer()->claimOwned($remoteReadGaps, [$this->entryWithWorklogId(0)], new TicketSystem());

        self::assertSame([42], $remoteReadGaps->unclaimedUnreadableWorklogIds());
    }

    public function testAnEntryOutsideTheWindowIsFoundByTheLookup(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('error', 42);
        $remoteReadGaps->record('error', 43);

        // 43 belongs to an entry the run did not carry; the lookup reports it.
        $claimer = $this->claimer([43 => $this->entryWithWorklogId(43)]);
        $claimer->claimOwned($remoteReadGaps, [$this->entryWithWorklogId(42)], new TicketSystem());

        self::assertSame([], $remoteReadGaps->unclaimedUnreadableWorklogIds());
        self::assertFalse($remoteReadGaps->hidesMoves());
    }

    public function testAWorklogNoEntryOwnsKeepsBlockingTheRun(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('error', 42);

        $this->claimer()->claimOwned($remoteReadGaps, [], new TicketSystem());

        self::assertSame([42], $remoteReadGaps->unclaimedUnreadableWorklogIds());
        self::assertTrue($remoteReadGaps->hidesMoves());
    }

    public function testACleanReadNeedsNoLookupAndStaysClean(): void
    {
        $remoteReadGaps = new RemoteReadGaps();

        $this->claimer()->claimOwned($remoteReadGaps, [$this->entryWithWorklogId(7)], new TicketSystem());

        self::assertFalse($remoteReadGaps->hidesMoves());
    }
}

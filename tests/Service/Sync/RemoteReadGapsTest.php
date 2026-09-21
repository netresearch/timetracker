<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\Service\Sync\RemoteReadGaps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a remote read could not see decides whether a missing worklog may be
 * called deleted. Each test below fixes one gap and the conclusion it blocks.
 *
 * @internal
 */
#[CoversClass(RemoteReadGaps::class)]
final class RemoteReadGapsTest extends TestCase
{
    public function testACleanReadHidesNothingAndAllowsEveryConclusion(): void
    {
        $remoteReadGaps = new RemoteReadGaps();

        self::assertFalse($remoteReadGaps->hidesMoves());
        self::assertTrue($remoteReadGaps->allowsConclusionAbout(1));
        self::assertSame([], $remoteReadGaps->unclaimedUnreadableWorklogIds());
    }

    public function testATruncatedSearchBlocksEveryConclusion(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('truncated');

        self::assertTrue($remoteReadGaps->hidesMoves());
        self::assertFalse($remoteReadGaps->allowsConclusionAbout(1));
        // The cap is not about one worklog, so none is listed as unreadable.
        self::assertSame([], $remoteReadGaps->unclaimedUnreadableWorklogIds());
    }

    public function testAnUnreadableIssueBlocksEveryConclusion(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('error');

        self::assertTrue($remoteReadGaps->hidesMoves());
        self::assertFalse($remoteReadGaps->allowsConclusionAbout(42));
    }

    public function testAnUnclaimedUnreadableWorklogBlocksEveryConclusion(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('error', 42);

        self::assertSame([42], $remoteReadGaps->unclaimedUnreadableWorklogIds());
        self::assertTrue($remoteReadGaps->hidesMoves());
        self::assertFalse($remoteReadGaps->allowsConclusionAbout(7));
    }

    public function testAClaimedUnreadableWorklogBlocksOnlyItself(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('error', 42);
        $remoteReadGaps->claim(42);

        self::assertSame([], $remoteReadGaps->unclaimedUnreadableWorklogIds());
        self::assertFalse($remoteReadGaps->hidesMoves());
        self::assertFalse($remoteReadGaps->allowsConclusionAbout(42));
        self::assertTrue($remoteReadGaps->allowsConclusionAbout(7));
    }

    public function testClaimingOneOfTwoUnreadableWorklogsStillBlocksTheRun(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('error', 42);
        $remoteReadGaps->record('error', 43);
        $remoteReadGaps->claim(42);

        self::assertSame([43], $remoteReadGaps->unclaimedUnreadableWorklogIds());
        self::assertTrue($remoteReadGaps->hidesMoves());
    }

    public function testClaimingAWorklogTheReadNeverFlaggedChangesNothing(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->claim(99);

        self::assertFalse($remoteReadGaps->hidesMoves());
        self::assertTrue($remoteReadGaps->allowsConclusionAbout(99));
    }

    public function testTheSameWorklogRecordedTwiceIsListedOnce(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('error', 42);
        $remoteReadGaps->record('error', 42);

        self::assertSame([42], $remoteReadGaps->unclaimedUnreadableWorklogIds());
    }

    public function testATruncatedSearchStaysBlockingAfterEveryWorklogIsClaimed(): void
    {
        $remoteReadGaps = new RemoteReadGaps();
        $remoteReadGaps->record('truncated');
        $remoteReadGaps->record('error', 42);
        $remoteReadGaps->claim(42);

        self::assertSame([], $remoteReadGaps->unclaimedUnreadableWorklogIds());
        self::assertTrue($remoteReadGaps->hidesMoves());
    }
}

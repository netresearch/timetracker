<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Entry;
use App\Entity\SyncRun;
use App\Entity\SyncRunItem;
use App\Enum\SyncItemKind;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One finding of a sync run: what kind it is, what it points at, and why.
 *
 * @internal
 */
#[CoversClass(SyncRunItem::class)]
final class SyncRunItemTest extends TestCase
{
    public function testAFreshItemIsAnErrorWithNothingAttached(): void
    {
        $syncRunItem = new SyncRunItem();

        // The default is the kind that needs no interpretation: a finding that
        // was never classified is an error, not a silent success.
        self::assertSame(SyncItemKind::ERROR, $syncRunItem->getKind());
        self::assertSame('', $syncRunItem->getReason());
        self::assertNull($syncRunItem->getId());
        self::assertNull($syncRunItem->getSyncRun());
        self::assertNull($syncRunItem->getIssueKey());
        self::assertNull($syncRunItem->getRemoteWorklogId());
        self::assertNull($syncRunItem->getEntry());
        self::assertNull($syncRunItem->getAuthor());
        self::assertNull($syncRunItem->getPayload());
        self::assertNull($syncRunItem->getCreatedAt());
    }

    public function testTheRunItBelongsToRoundTrips(): void
    {
        $syncRun = new SyncRun();
        $syncRunItem = new SyncRunItem();

        self::assertSame($syncRunItem, $syncRunItem->setSyncRun($syncRun));
        self::assertSame($syncRun, $syncRunItem->getSyncRun());
    }

    public function testTheKindRoundTrips(): void
    {
        $syncRunItem = new SyncRunItem();
        $syncRunItem->setKind(SyncItemKind::CONFLICT);

        self::assertSame(SyncItemKind::CONFLICT, $syncRunItem->getKind());
    }

    public function testTheRemoteSideRoundTripsAndAcceptsNull(): void
    {
        $syncRunItem = new SyncRunItem();
        $syncRunItem->setIssueKey('TT-42');
        $syncRunItem->setRemoteWorklogId(987654321);
        $syncRunItem->setAuthor('sebastian.mendel');

        self::assertSame('TT-42', $syncRunItem->getIssueKey());
        self::assertSame(987654321, $syncRunItem->getRemoteWorklogId());
        self::assertSame('sebastian.mendel', $syncRunItem->getAuthor());

        // A finding about a worklog with no local counterpart carries none of them.
        $syncRunItem->setIssueKey(null);
        $syncRunItem->setRemoteWorklogId(null);
        $syncRunItem->setAuthor(null);

        self::assertNull($syncRunItem->getIssueKey());
        self::assertNull($syncRunItem->getRemoteWorklogId());
        self::assertNull($syncRunItem->getAuthor());
    }

    public function testTheLocalEntryCanBeSetAndCleared(): void
    {
        $entry = new Entry();
        $syncRunItem = new SyncRunItem();

        $syncRunItem->setEntry($entry);
        self::assertSame($entry, $syncRunItem->getEntry());

        // The join column is ON DELETE SET NULL: a deleted entry leaves its finding.
        $syncRunItem->setEntry(null);
        self::assertNull($syncRunItem->getEntry());
    }

    public function testTheReasonRoundTrips(): void
    {
        $syncRunItem = new SyncRunItem();
        $syncRunItem->setReason('remote worklog has no local entry');

        self::assertSame('remote worklog has no local entry', $syncRunItem->getReason());
    }

    public function testThePayloadRoundTripsAndCanBeCleared(): void
    {
        $payload = ['remote' => ['seconds' => 3600], 'local' => ['seconds' => 1800]];
        $syncRunItem = new SyncRunItem();

        $syncRunItem->setPayload($payload);
        self::assertSame($payload, $syncRunItem->getPayload());

        $syncRunItem->setPayload(null);
        self::assertNull($syncRunItem->getPayload());
    }

    public function testTheCreationTimestampRoundTrips(): void
    {
        $createdAt = new DateTimeImmutable('2026-09-21 04:15:00');
        $syncRunItem = new SyncRunItem();
        $syncRunItem->setCreatedAt($createdAt);

        self::assertSame($createdAt, $syncRunItem->getCreatedAt());
    }
}

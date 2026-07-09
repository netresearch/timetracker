<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\SyncRun;
use App\Entity\SyncRunItem;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SyncRunTest extends TestCase
{
    public function testCountersIncrementFromZero(): void
    {
        $syncRun = new SyncRun();
        $syncRun->setCounters([]);
        $syncRun->incrementCounter('in_sync');
        $syncRun->incrementCounter('in_sync');
        $syncRun->incrementCounter('errors');

        self::assertSame(['in_sync' => 2, 'errors' => 1], $syncRun->getCounters());
    }

    public function testAddItemLinksBothSides(): void
    {
        $syncRun = new SyncRun();
        $item = new SyncRunItem()
            ->setKind(SyncItemKind::REMOTE_ONLY)
            ->setIssueKey('ABC-1')
            ->setReason('worklog 42 has no matching entry')
            ->setCreatedAt(new DateTimeImmutable('2026-07-09 12:00:00'));

        $syncRun->addItem($item);

        self::assertSame([$item], $syncRun->getItems()->toArray());
        self::assertSame($syncRun, $item->getSyncRun());
    }

    public function testFluentSetters(): void
    {
        $syncRun = new SyncRun()
            ->setType(SyncRunType::VERIFY)
            ->setStatus(SyncRunStatus::RUNNING)
            ->setScope(['from' => '2026-06-01', 'to' => '2026-06-30'])
            ->setStartedAt(new DateTimeImmutable('2026-07-09 12:00:00'));

        self::assertSame(SyncRunType::VERIFY, $syncRun->getType());
        self::assertSame(SyncRunStatus::RUNNING, $syncRun->getStatus());
        self::assertNull($syncRun->getFinishedAt());
    }
}

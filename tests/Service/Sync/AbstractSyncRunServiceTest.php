<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\Entity\SyncRun;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Service\Sync\AbstractSyncRunService;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Tests\Fixtures\ExposedSyncRunService;

#[CoversClass(AbstractSyncRunService::class)]
final class AbstractSyncRunServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private ExposedSyncRunService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new ExposedSyncRunService($this->entityManager, new MockClock('2026-07-09 12:00:00'));
    }

    public function testSuccessfulBodyCompletesAndFlushesTheRun(): void
    {
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->expects(self::once())->method('flush');

        $syncRun = $this->service->run(new SyncRun(), static function (): void {
        });

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertNotNull($syncRun->getFinishedAt());
    }

    public function testFailingBodyIsRecordedAsErrorItemAndTheRunStillFlushes(): void
    {
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->expects(self::once())->method('flush');

        $syncRun = $this->service->run(new SyncRun(), static function (): void {
            throw new LogicException('boom');
        });

        self::assertSame(SyncRunStatus::FAILED, $syncRun->getStatus());
        self::assertCount(1, $syncRun->getItems());
        $item = $syncRun->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame(SyncItemKind::ERROR, $item->getKind());
        self::assertSame('boom', $item->getReason());
    }

    public function testClosedEntityManagerSurfacesTheOriginalCause(): void
    {
        $this->entityManager->method('isOpen')->willReturn(false);
        $this->entityManager->expects(self::never())->method('flush');

        $original = new LogicException('constraint violated');

        try {
            $this->service->run(new SyncRun(), static function () use ($original): void {
                throw $original;
            });
            self::fail('expected the aborted-run exception');
        } catch (RuntimeException $runtimeException) {
            self::assertSame(
                'Sync run aborted: the entity manager closed mid-run (a persisted row was rejected by the database). Original error: constraint violated',
                $runtimeException->getMessage(),
            );
            self::assertSame(0, $runtimeException->getCode());
            self::assertSame($original, $runtimeException->getPrevious());
        }
    }
}

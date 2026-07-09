<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\Enum\SyncAction;
use App\Enum\WorklogField;
use App\Service\Sync\ReconciliationService;
use App\ValueObject\Sync\WorklogSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReconciliationServiceTest extends TestCase
{
    private ReconciliationService $service;

    protected function setUp(): void
    {
        $this->service = new ReconciliationService();
    }

    private static function snap(string $key = 'ABC-1', int $ts = 1751871600, int $min = 60, string $comment = 'c'): WorklogSnapshot
    {
        return new WorklogSnapshot($key, $ts, $min, $comment);
    }

    /**
     * @return array<string, array{0: ?WorklogSnapshot, 1: ?WorklogSnapshot, 2: ?WorklogSnapshot, 3: SyncAction, 4: list<WorklogField>}>
     */
    public static function matrixProvider(): array
    {
        $base = self::snap();

        return [
            'nothing on either side' => [null, null, null, SyncAction::NONE, []],
            'remote only -> create local' => [null, null, self::snap(), SyncAction::CREATE_LOCAL, []],
            'local only, linked -> remote missing' => [$base, self::snap(), null, SyncAction::REMOTE_MISSING, []],
            'local only, no base -> remote missing' => [null, self::snap(), null, SyncAction::REMOTE_MISSING, []],
            'no base, both equal -> none' => [null, self::snap(), self::snap(), SyncAction::NONE, []],
            'no base, differ -> diverged' => [null, self::snap(), self::snap(min: 90), SyncAction::DIVERGED, [WorklogField::DURATION]],
            'clean clean -> none' => [$base, self::snap(), self::snap(), SyncAction::NONE, []],
            'local dirty, remote clean -> push' => [$base, self::snap(min: 90), self::snap(), SyncAction::PUSH, [WorklogField::DURATION]],
            'remote dirty, local clean -> pull' => [$base, self::snap(), self::snap(comment: 'edited'), SyncAction::PULL, [WorklogField::COMMENT]],
            'both dirty, disjoint fields -> merge' => [$base, self::snap(min: 90), self::snap(comment: 'edited'), SyncAction::MERGE, [WorklogField::DURATION, WorklogField::COMMENT]],
            'both dirty, same field -> conflict' => [$base, self::snap(min: 90), self::snap(min: 120), SyncAction::CONFLICT, [WorklogField::DURATION]],
            'both dirty, overlapping field set -> conflict' => [$base, self::snap(min: 90, comment: 'mine'), self::snap(comment: 'theirs'), SyncAction::CONFLICT, [WorklogField::COMMENT]],
            'both changed identically -> none' => [$base, self::snap(min: 90), self::snap(min: 90), SyncAction::NONE, []],
        ];
    }

    /**
     * @param list<WorklogField> $expectedFields
     */
    #[DataProvider('matrixProvider')]
    public function testMatrix(?WorklogSnapshot $base, ?WorklogSnapshot $local, ?WorklogSnapshot $remote, SyncAction $expectedAction, array $expectedFields): void
    {
        $decision = $this->service->reconcile($base, $local, $remote);

        self::assertSame($expectedAction, $decision->action);
        self::assertSame($expectedFields, $decision->fields);
    }
}

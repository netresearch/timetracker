<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\Response\SyncConflictDto;
use App\Dto\Response\SyncRunDto;
use App\Dto\Response\SyncRunItemDto;
use App\Entity\Entry;
use App\Entity\SyncRun;
use App\Entity\SyncRunItem;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Enum\WorklogSyncStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for the sync-surface response DTOs (ADR-023 Phase 4a):
 * run, run item, and parked-conflict serialization shapes.
 *
 * @internal
 */
#[CoversClass(SyncRunDto::class)]
#[CoversClass(SyncRunItemDto::class)]
#[CoversClass(SyncConflictDto::class)]
final class SyncResponseDtosTest extends TestCase
{
    public function testRunDtoSerializesFullShapeWithItems(): void
    {
        $syncRun = $this->buildRun();

        $serialized = SyncRunDto::fromEntity($syncRun)->jsonSerialize();

        self::assertSame([
            'id' => 7,
            'type' => 'sync',
            'status' => 'completed',
            'ticket_system_id' => 1,
            'triggered_by' => 'developer',
            'scope' => ['users' => ['developer'], 'from' => '2026-07-01', 'to' => '2026-07-07'],
            'counters' => ['written' => 2, 'conflict' => 1],
            'started_at' => '2026-07-08T10:00:00+00:00',
            'finished_at' => '2026-07-08T10:00:05+00:00',
            'items' => [
                [
                    'kind' => 'diverged',
                    'issue_key' => 'ABC-1',
                    'remote_worklog_id' => 4711,
                    'entry_id' => 5,
                    'author' => 'developer',
                    'reason' => 'remote changed since base',
                    'payload' => ['comment' => 'remote text'],
                    'created_at' => '2026-07-08T10:00:03+00:00',
                ],
            ],
        ], $serialized);
    }

    public function testRunDtoWithoutItemsOmitsKey(): void
    {
        $syncRun = $this->buildRun();

        $serialized = SyncRunDto::fromEntity($syncRun, withItems: false)->jsonSerialize();

        self::assertArrayNotHasKey('items', $serialized);
        self::assertSame(7, $serialized['id']);
    }

    public function testRunDtoWithUnfinishedRunSerializesNullFinishedAt(): void
    {
        $syncRun = $this->buildRun();
        $syncRun->setStatus(SyncRunStatus::RUNNING);
        $syncRun->setFinishedAt(null);

        $serialized = SyncRunDto::fromEntity($syncRun, withItems: false)->jsonSerialize();

        self::assertSame('running', $serialized['status']);
        self::assertNull($serialized['finished_at']);
    }

    public function testRunItemDtoSerializesNullableFieldsAsNull(): void
    {
        $item = new SyncRunItem();
        $item->setKind(SyncItemKind::ERROR);
        $item->setReason('boom');
        $item->setCreatedAt(new DateTimeImmutable('2026-07-08T10:00:03+00:00'));

        self::assertSame([
            'kind' => 'error',
            'issue_key' => null,
            'remote_worklog_id' => null,
            'entry_id' => null,
            'author' => null,
            'reason' => 'boom',
            'payload' => null,
            'created_at' => '2026-07-08T10:00:03+00:00',
        ], SyncRunItemDto::fromEntity($item)->jsonSerialize());
    }

    public function testConflictDtoSerializesFullShape(): void
    {
        $state = $this->buildConflictState();

        self::assertSame([
            'id' => 33,
            'status' => 'conflict',
            'entry' => [
                'id' => 5,
                'user' => 'developer',
                'ticket' => 'ABC-1',
                'day' => '2026-07-01',
                'start' => '09:00:00',
                'end' => '10:30:00',
                'duration' => 90,
                'description' => 'local text',
            ],
            'base_payload' => ['comment' => 'base text', 'started' => '2026-07-01T09:00:00.000+0000'],
            'base_updated_at' => '2026-07-02T08:00:00.000+0000',
            'conflict_remote' => ['comment' => 'remote text'],
            'last_synced_at' => '2026-07-08T10:00:00+00:00',
        ], SyncConflictDto::fromEntity($state)->jsonSerialize());
    }

    public function testConflictDtoWithoutRemotePayloadSerializesNull(): void
    {
        $state = $this->buildConflictState();
        $state->setStatus(WorklogSyncStatus::ORPHANED);
        $state->setConflictRemotePayload(null);

        $serialized = SyncConflictDto::fromEntity($state)->jsonSerialize();

        self::assertSame('orphaned', $serialized['status']);
        self::assertNull($serialized['conflict_remote']);
    }

    private function buildRun(): SyncRun
    {
        $ticketSystem = new TicketSystem();
        new ReflectionProperty(TicketSystem::class, 'id')->setValue($ticketSystem, 1);

        $user = new User();
        $user->setId(2);
        $user->setUsername('developer');

        $syncRun = new SyncRun();
        new ReflectionProperty(SyncRun::class, 'id')->setValue($syncRun, 7);
        $syncRun->setType(SyncRunType::SYNC);
        $syncRun->setStatus(SyncRunStatus::COMPLETED);
        $syncRun->setTicketSystem($ticketSystem);
        $syncRun->setTriggeredBy($user);
        $syncRun->setScope(['users' => ['developer'], 'from' => '2026-07-01', 'to' => '2026-07-07']);
        $syncRun->setCounters(['written' => 2, 'conflict' => 1]);
        $syncRun->setStartedAt(new DateTimeImmutable('2026-07-08T10:00:00+00:00'));
        $syncRun->setFinishedAt(new DateTimeImmutable('2026-07-08T10:00:05+00:00'));

        $item = new SyncRunItem();
        $item->setKind(SyncItemKind::DIVERGED);
        $item->setIssueKey('ABC-1');
        $item->setRemoteWorklogId(4711);
        $item->setEntry($this->buildEntry());
        $item->setAuthor('developer');
        $item->setReason('remote changed since base');
        $item->setPayload(['comment' => 'remote text']);
        $item->setCreatedAt(new DateTimeImmutable('2026-07-08T10:00:03+00:00'));
        $syncRun->addItem($item);

        return $syncRun;
    }

    private function buildConflictState(): WorklogSyncState
    {
        $ticketSystem = new TicketSystem();
        new ReflectionProperty(TicketSystem::class, 'id')->setValue($ticketSystem, 1);

        $state = new WorklogSyncState();
        new ReflectionProperty(WorklogSyncState::class, 'id')->setValue($state, 33);
        $state->setEntry($this->buildEntry());
        $state->setTicketSystem($ticketSystem);
        $state->setStatus(WorklogSyncStatus::CONFLICT);
        $state->setBasePayload(['comment' => 'base text', 'started' => '2026-07-01T09:00:00.000+0000']);
        $state->setBaseUpdatedAt('2026-07-02T08:00:00.000+0000');
        $state->setConflictRemotePayload(['comment' => 'remote text']);
        $state->setLastSyncedAt(new DateTimeImmutable('2026-07-08T10:00:00+00:00'));

        return $state;
    }

    private function buildEntry(): Entry
    {
        $user = new User();
        $user->setId(2);
        $user->setUsername('developer');

        $entry = new Entry();
        $entry->setId(5);
        $entry->setUser($user);
        $entry->setTicket('ABC-1');
        $entry->setDay('2026-07-01');
        $entry->setStart('09:00:00');
        $entry->setEnd('10:30:00');
        $entry->setDuration(90);
        $entry->setDescription('local text');

        return $entry;
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Enum\WorklogSyncStatus;
use App\Repository\SyncRunRepository;
use App\Repository\WorklogSyncStateRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

use function array_map;
use function assert;

/**
 * @internal
 *
 * @coversNothing
 */
final class SyncSurfaceQueriesTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    private TicketSystem $ticketSystem;

    private Project $project;

    private User $admin;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $ticketSystem = $this->entityManager->find(TicketSystem::class, 1);
        assert($ticketSystem instanceof TicketSystem);
        $this->ticketSystem = $ticketSystem;

        $project = $this->entityManager->find(Project::class, 2);
        assert($project instanceof Project);
        $project->setTicketSystem($this->ticketSystem);
        $this->project = $project;

        $admin = $this->entityManager->find(User::class, 1);
        assert($admin instanceof User);
        $this->admin = $admin;

        $developer = $this->entityManager->find(User::class, 2);
        assert($developer instanceof User);
        $this->developer = $developer;

        $this->entityManager->flush();
    }

    public function testFindLatestOrdersNewestFirst(): void
    {
        $older = $this->createRun($this->ticketSystem, new DateTimeImmutable('2026-07-01 10:00:00'));
        $newer = $this->createRun($this->ticketSystem, new DateTimeImmutable('2026-07-02 10:00:00'));
        $this->entityManager->flush();

        $syncRunRepository = self::getContainer()->get(SyncRunRepository::class);
        assert($syncRunRepository instanceof SyncRunRepository);

        $runs = $syncRunRepository->findLatest();

        $ids = array_map(static fn (SyncRun $run): ?int => $run->getId(), $runs);
        self::assertContains($newer->getId(), $ids);
        self::assertContains($older->getId(), $ids);
        self::assertLessThan(
            array_search($older->getId(), $ids, true),
            array_search($newer->getId(), $ids, true),
            'Newest run must come before the older run.',
        );

        $limited = $syncRunRepository->findLatest(1);
        self::assertCount(1, $limited);
        self::assertSame($newer->getId(), $limited[0]->getId());
    }

    public function testFindLatestFiltersByTicketSystem(): void
    {
        $otherTicketSystem = new TicketSystem();
        $otherTicketSystem->setName('otherSystem');
        $otherTicketSystem->setUrl('https://other.example.com');
        $otherTicketSystem->setTicketUrl('https://other.example.com/browse/{ticket}');
        $otherTicketSystem->setLogin('other');
        $otherTicketSystem->setPassword('other');
        $this->entityManager->persist($otherTicketSystem);

        $runOnDefault = $this->createRun($this->ticketSystem, new DateTimeImmutable('2026-07-01 10:00:00'));
        $runOnOther = $this->createRun($otherTicketSystem, new DateTimeImmutable('2026-07-02 10:00:00'));
        $this->entityManager->flush();

        $syncRunRepository = self::getContainer()->get(SyncRunRepository::class);
        assert($syncRunRepository instanceof SyncRunRepository);

        $runs = $syncRunRepository->findLatest(20, $otherTicketSystem);

        $ids = array_map(static fn (SyncRun $run): ?int => $run->getId(), $runs);
        self::assertContains($runOnOther->getId(), $ids);
        self::assertNotContains($runOnDefault->getId(), $ids);
    }

    public function testFindParkedReturnsOnlyParkedStates(): void
    {
        $conflict = $this->createState(
            $this->createEntry($this->developer, '2026-06-15'),
            WorklogSyncStatus::CONFLICT,
            new DateTimeImmutable('2026-07-01 08:00:00'),
        );
        $orphaned = $this->createState(
            $this->createEntry($this->developer, '2026-06-16'),
            WorklogSyncStatus::ORPHANED,
            new DateTimeImmutable('2026-07-02 08:00:00'),
        );
        $inSync = $this->createState(
            $this->createEntry($this->developer, '2026-06-17'),
            WorklogSyncStatus::IN_SYNC,
            new DateTimeImmutable('2026-07-03 08:00:00'),
        );
        $this->entityManager->flush();

        $stateRepository = self::getContainer()->get(WorklogSyncStateRepository::class);
        assert($stateRepository instanceof WorklogSyncStateRepository);

        $parked = $stateRepository->findParked();

        $ids = array_map(static fn (WorklogSyncState $state): ?int => $state->getId(), $parked);
        self::assertContains($conflict->getId(), $ids);
        self::assertContains($orphaned->getId(), $ids);
        self::assertNotContains($inSync->getId(), $ids);
        self::assertLessThan(
            array_search($conflict->getId(), $ids, true),
            array_search($orphaned->getId(), $ids, true),
            'Most recently synced parked state must come first.',
        );
    }

    public function testFindParkedFiltersByUser(): void
    {
        $developerState = $this->createState(
            $this->createEntry($this->developer, '2026-06-15'),
            WorklogSyncStatus::CONFLICT,
            new DateTimeImmutable('2026-07-01 08:00:00'),
        );
        $adminState = $this->createState(
            $this->createEntry($this->admin, '2026-06-16'),
            WorklogSyncStatus::CONFLICT,
            new DateTimeImmutable('2026-07-02 08:00:00'),
        );
        $this->entityManager->flush();

        $stateRepository = self::getContainer()->get(WorklogSyncStateRepository::class);
        assert($stateRepository instanceof WorklogSyncStateRepository);

        $parked = $stateRepository->findParked($this->developer);

        $ids = array_map(static fn (WorklogSyncState $state): ?int => $state->getId(), $parked);
        self::assertContains($developerState->getId(), $ids);
        self::assertNotContains($adminState->getId(), $ids);
    }

    public function testFindParkedByIdRejectsInSyncRows(): void
    {
        $conflict = $this->createState(
            $this->createEntry($this->developer, '2026-06-15'),
            WorklogSyncStatus::CONFLICT,
            new DateTimeImmutable('2026-07-01 08:00:00'),
        );
        $inSync = $this->createState(
            $this->createEntry($this->developer, '2026-06-16'),
            WorklogSyncStatus::IN_SYNC,
            new DateTimeImmutable('2026-07-02 08:00:00'),
        );
        $this->entityManager->flush();

        $stateRepository = self::getContainer()->get(WorklogSyncStateRepository::class);
        assert($stateRepository instanceof WorklogSyncStateRepository);

        $conflictId = $conflict->getId();
        $inSyncId = $inSync->getId();
        self::assertNotNull($conflictId);
        self::assertNotNull($inSyncId);

        self::assertSame($conflictId, $stateRepository->findParkedById($conflictId)?->getId());
        self::assertNull($stateRepository->findParkedById($inSyncId));
    }

    private function createRun(TicketSystem $ticketSystem, DateTimeImmutable $startedAt): SyncRun
    {
        $syncRun = new SyncRun()
            ->setType(SyncRunType::VERIFY)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setTicketSystem($ticketSystem)
            ->setTriggeredBy($this->admin)
            ->setScope([])
            ->setCounters([])
            ->setStartedAt($startedAt);
        $this->entityManager->persist($syncRun);

        return $syncRun;
    }

    private function createEntry(User $user, string $day): Entry
    {
        $entry = new Entry()
            ->setUser($user)->setProject($this->project)->setTicket('TIM-1')
            ->setDay(new DateTime($day))->setStart('09:00:00')->setEnd('10:00:00');
        $entry->setDuration(60);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function createState(Entry $entry, WorklogSyncStatus $status, DateTimeImmutable $lastSyncedAt): WorklogSyncState
    {
        $state = new WorklogSyncState()
            ->setEntry($entry)
            ->setTicketSystem($this->ticketSystem)
            ->setStatus($status)
            ->setLastSyncedAt($lastSyncedAt);
        $this->entityManager->persist($state);

        return $state;
    }
}

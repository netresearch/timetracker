<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Traits;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\WorklogSyncStatus;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function assert;

/**
 * Shared fixtures for worklog-sync surface tests: ticket system 1 with
 * project 2 booked on it, the admin ('unittest', id 1) and non-admin
 * ('developer', id 2) fixture users, plus entry/state builders.
 */
trait CreatesWorklogSyncFixtures
{
    private EntityManagerInterface $entityManager;

    private TicketSystem $ticketSystem;

    private Project $project;

    private User $admin;

    private User $developer;

    private function setUpWorklogSyncFixtures(): void
    {
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

    private function createSyncEntry(User $user, string $day): Entry
    {
        $entry = new Entry()
            ->setUser($user)->setProject($this->project)->setTicket('TIM-1')
            ->setDay(new DateTime($day))->setStart('09:00:00')->setEnd('10:00:00');
        $entry->setDuration(60);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function createSyncState(Entry $entry, WorklogSyncStatus $status, ?DateTimeImmutable $lastSyncedAt = null): WorklogSyncState
    {
        $state = new WorklogSyncState()
            ->setEntry($entry)
            ->setTicketSystem($this->ticketSystem)
            ->setStatus($status)
            ->setLastSyncedAt($lastSyncedAt ?? new DateTimeImmutable('2026-07-01 08:00:00'));
        $this->entityManager->persist($state);

        return $state;
    }
}

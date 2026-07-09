<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\ProjectRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

use function assert;

/**
 * @internal
 *
 * @coversNothing
 */
final class ImportFindersTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    private TicketSystem $ticketSystem;

    private Project $project;

    private User $user;

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
        $user = $this->entityManager->find(User::class, 2);
        assert($user instanceof User);
        $this->user = $user;
        $this->project = $project;
        $this->entityManager->flush();
    }

    public function testFindByTicketSystemReturnsLinkedProjects(): void
    {
        $projectRepository = self::getContainer()->get(ProjectRepository::class);
        assert($projectRepository instanceof ProjectRepository);

        $projects = $projectRepository->findByTicketSystem($this->ticketSystem);

        $ids = array_map(static fn (Project $project): ?int => $project->getId(), $projects);
        self::assertContains(2, $ids);
        self::assertNotContains(1, $ids);
    }

    public function testFindOneByWorklogIdAndTicketSystem(): void
    {
        $entry = new Entry()
            ->setUser($this->user)->setProject($this->project)->setTicket('TIM-1')
            ->setDay(new DateTime('2026-06-15'))->setStart('09:00:00')->setEnd('10:00:00')
            ->setWorklogId(987654);
        $entry->setDuration(60);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        assert($entryRepository instanceof EntryRepository);

        $found = $entryRepository->findOneByWorklogIdAndTicketSystem(987654, $this->ticketSystem);
        self::assertSame($entry->getId(), $found?->getId());
        self::assertNull($entryRepository->findOneByWorklogIdAndTicketSystem(111111, $this->ticketSystem));
    }

    public function testFindUnlinkedDuplicate(): void
    {
        $unlinked = new Entry()
            ->setUser($this->user)->setProject($this->project)->setTicket('TIM-1')
            ->setDay(new DateTime('2026-06-16'))->setStart('09:00:00')->setEnd('10:30:00');
        $unlinked->setDuration(90);
        $this->entityManager->persist($unlinked);
        $this->entityManager->flush();

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        assert($entryRepository instanceof EntryRepository);

        $hit = $entryRepository->findUnlinkedDuplicate($this->user, 'TIM-1', new DateTime('2026-06-16'), 90);
        self::assertSame($unlinked->getId(), $hit?->getId());
        self::assertNull($entryRepository->findUnlinkedDuplicate($this->user, 'TIM-1', new DateTime('2026-06-16'), 45));
    }
}

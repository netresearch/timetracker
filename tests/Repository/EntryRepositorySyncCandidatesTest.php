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
use App\Enum\EntrySource;
use App\Repository\EntryRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class EntryRepositorySyncCandidatesTest extends AbstractWebTestCase
{
    public function testFindJiraSyncCandidatesFiltersByUserSystemRangeAndTicket(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entryRepository);

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');
        $ticketSystem = $entityManager->getRepository(TicketSystem::class)->findOneBy([]);
        self::assertInstanceOf(TicketSystem::class, $ticketSystem, 'fixture ticket system missing');
        $project = $entityManager->getRepository(Project::class)->findOneBy([]);
        self::assertInstanceOf(Project::class, $project, 'fixture project missing');
        $project->setTicketSystem($ticketSystem);

        $inRange = new Entry()
            ->setUser($user)->setProject($project)->setTicket('ABC-1')
            ->setDay(new DateTime('2026-06-15'))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00'));
        $noTicket = new Entry()
            ->setUser($user)->setProject($project)->setTicket('')
            ->setDay(new DateTime('2026-06-15'))->setStart(new DateTime('10:00'))->setEnd(new DateTime('11:00'));
        $outOfRange = new Entry()
            ->setUser($user)->setProject($project)->setTicket('ABC-2')
            ->setDay(new DateTime('2026-07-15'))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00'));
        // ADR-025 §7: agent walltime is never pushed as a worklog, so the sync must not pick it up.
        $agentWalltime = new Entry()
            ->setUser($user)->setProject($project)->setTicket('ABC-3')->setSource(EntrySource::AGENT)
            ->setDay(new DateTime('2026-06-15'))->setStart(new DateTime('09:00'))->setEnd(new DateTime('11:00'));

        $entityManager->persist($inRange);
        $entityManager->persist($noTicket);
        $entityManager->persist($outOfRange);
        $entityManager->persist($agentWalltime);
        $entityManager->flush();

        $result = $entryRepository->findJiraSyncCandidates($user, $ticketSystem, new DateTime('2026-06-01'), new DateTime('2026-06-30'));

        $ids = array_map(static fn (Entry $entry): ?int => $entry->getId(), $result);
        self::assertContains($inRange->getId(), $ids);
        self::assertNotContains($noTicket->getId(), $ids);
        self::assertNotContains($outOfRange->getId(), $ids);
        self::assertNotContains($agentWalltime->getId(), $ids);
    }

    public function testFindByUserAndTicketSystemToSyncSkipsAgentWalltime(): void
    {
        // The legacy bulk push (JiraOAuthApiService / JiraWorkLogService) books every
        // entry this returns; agent walltime must not be among them (ADR-025 §7).
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entryRepository);

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');
        $ticketSystem = $entityManager->getRepository(TicketSystem::class)->findOneBy([]);
        self::assertInstanceOf(TicketSystem::class, $ticketSystem, 'fixture ticket system missing');
        $project = $entityManager->getRepository(Project::class)->findOneBy([]);
        self::assertInstanceOf(Project::class, $project, 'fixture project missing');
        $project->setTicketSystem($ticketSystem);

        $human = new Entry()
            ->setUser($user)->setProject($project)->setTicket('ABC-10')
            ->setDay(new DateTime('2030-01-02'))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00'));
        $agentWalltime = new Entry()
            ->setUser($user)->setProject($project)->setTicket('ABC-10')->setSource(EntrySource::AGENT)
            ->setDay(new DateTime('2030-01-02'))->setStart(new DateTime('09:00'))->setEnd(new DateTime('12:00'));

        $entityManager->persist($human);
        $entityManager->persist($agentWalltime);
        $entityManager->flush();

        $userId = $user->getId();
        $ticketSystemId = $ticketSystem->getId();
        self::assertIsInt($userId);
        self::assertIsInt($ticketSystemId);
        // Newest day first, so the two 2030 entries lead the result.
        $result = $entryRepository->findByUserAndTicketSystemToSync($userId, $ticketSystemId, 50);

        $ids = array_map(static fn (Entry $entry): ?int => $entry->getId(), $result);
        self::assertContains($human->getId(), $ids);
        self::assertNotContains($agentWalltime->getId(), $ids);
    }
}

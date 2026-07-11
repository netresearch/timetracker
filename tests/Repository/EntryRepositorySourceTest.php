<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use App\Entity\Entry;
use App\Entity\User;
use App\Enum\EntrySource;
use App\Enum\Period;
use App\Repository\EntryRepository;
use App\Service\ClockInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

/**
 * ADR-025 Task 8: source-aware findByDay/getWorkByUser reads.
 *
 * @internal
 *
 * @coversNothing
 */
final class EntryRepositorySourceTest extends AbstractWebTestCase
{
    public function testFindByDayFiltersBySource(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entryRepository);

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');
        $userId = (int) $user->getId();
        $day = '2099-06-15';

        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::HUMAN)->setDuration(60)
                ->setDay(new DateTime($day))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00')),
        );
        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::AGENT)->setDuration(180)
                ->setDay(new DateTime($day))->setStart(new DateTime('10:00'))->setEnd(new DateTime('13:00')),
        );
        $entityManager->flush();

        self::assertCount(2, $entryRepository->findByDay($userId, $day), 'no filter returns both');
        self::assertCount(2, $entryRepository->findByDay($userId, $day, null), 'null filter returns both (back-compat)');

        $humanOnly = $entryRepository->findByDay($userId, $day, EntrySource::HUMAN);
        self::assertCount(1, $humanOnly, 'human filter excludes the agent entry');
        self::assertSame(60, $humanOnly[0]->getDuration());

        $agentOnly = $entryRepository->findByDay($userId, $day, EntrySource::AGENT);
        self::assertCount(1, $agentOnly);
        self::assertSame(180, $agentOnly[0]->getDuration());
    }

    public function testFindByDateFiltersBySource(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entryRepository);

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');
        $userId = (int) $user->getId();

        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::HUMAN)->setDuration(60)
                ->setDay(new DateTime('2099-07-15'))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00')),
        );
        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::AGENT)->setDuration(180)
                ->setDay(new DateTime('2099-07-15'))->setStart(new DateTime('10:00'))->setEnd(new DateTime('13:00')),
        );
        $entityManager->flush();

        self::assertCount(2, $entryRepository->findByDate($userId, 2099, 7), 'no source filter returns both (back-compat)');

        $humanOnly = $entryRepository->findByDate($userId, 2099, 7, null, null, null, EntrySource::HUMAN);
        self::assertCount(1, $humanOnly, 'human filter excludes the agent entry');
        self::assertSame(60, $humanOnly[0]->getDuration());

        $agentOnly = $entryRepository->findByDate($userId, 2099, 7, null, null, null, EntrySource::AGENT);
        self::assertCount(1, $agentOnly);
        self::assertSame(180, $agentOnly[0]->getDuration());
    }

    public function testGetRawDataExposesSourceAndResponsible(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entryRepository);

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');
        $userId = (int) $user->getId();
        $day = '2099-08-20';

        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::HUMAN)->setDuration(60)
                ->setDay(new DateTime($day))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00')),
        );
        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::AGENT)->setResponsibleUser($user)->setDuration(180)
                ->setDay(new DateTime($day))->setStart(new DateTime('10:00'))->setEnd(new DateTime('13:00')),
        );
        $entityManager->flush();

        $rows = $entryRepository->getRawData($day, $day, $userId);
        self::assertCount(2, $rows);

        $sources = array_map(static fn (array $row): mixed => $row['source'], $rows);
        self::assertContains('human', $sources, 'raw rows carry the source axis');
        self::assertContains('agent', $sources);

        foreach ($rows as $row) {
            self::assertArrayHasKey('responsible', $row, 'raw rows carry the responsible attribution');
        }
    }

    public function testGetActivitiesAndUsersWithTimeAreSourceAware(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entryRepository);

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');
        $ticket = 'TSRC-' . bin2hex(random_bytes(4));

        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::HUMAN)->setDuration(60)->setTicket($ticket)
                ->setDay(new DateTime('2099-10-01'))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00')),
        );
        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::AGENT)->setDuration(180)->setTicket($ticket)
                ->setDay(new DateTime('2099-10-01'))->setStart(new DateTime('10:00'))->setEnd(new DateTime('13:00')),
        );
        $entityManager->flush();

        self::assertSame(240, self::sumTotalTime($entryRepository->getActivitiesWithTime($ticket)), 'no filter folds both (back-compat)');
        self::assertSame(60, self::sumTotalTime($entryRepository->getActivitiesWithTime($ticket, EntrySource::HUMAN)), 'human-only excludes agent');
        self::assertSame(180, self::sumTotalTime($entryRepository->getActivitiesWithTime($ticket, EntrySource::AGENT)));

        self::assertSame(240, self::sumTotalTime($entryRepository->getUsersWithTime($ticket)), 'no filter folds both (back-compat)');
        self::assertSame(60, self::sumTotalTime($entryRepository->getUsersWithTime($ticket, EntrySource::HUMAN)), 'human-only excludes agent');
        self::assertSame(180, self::sumTotalTime($entryRepository->getUsersWithTime($ticket, EntrySource::AGENT)));
    }

    /**
     * @param array<int, array{total_time: int, ...}> $rows
     */
    private static function sumTotalTime(array $rows): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += $row['total_time'];
        }

        return $total;
    }

    public function testGetWorkByUserSumsHumanSourceOnly(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entryRepository);

        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $today = $clock->today();

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');
        $userId = (int) $user->getId();

        $baseHuman = $entryRepository->getWorkByUser($userId, Period::DAY, EntrySource::HUMAN)['duration'];
        $baseAll = $entryRepository->getWorkByUser($userId, Period::DAY)['duration'];

        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::HUMAN)->setDuration(60)
                ->setDay(DateTime::createFromInterface($today))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00')),
        );
        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::AGENT)->setDuration(180)
                ->setDay(DateTime::createFromInterface($today))->setStart(new DateTime('10:00'))->setEnd(new DateTime('13:00')),
        );
        $entityManager->flush();

        self::assertSame($baseHuman + 60, $entryRepository->getWorkByUser($userId, Period::DAY, EntrySource::HUMAN)['duration'], 'human-only adds 60, not 240');
        self::assertSame($baseAll + 240, $entryRepository->getWorkByUser($userId, Period::DAY)['duration'], 'unfiltered adds both (back-compat)');
    }
}

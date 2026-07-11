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
use App\Repository\EntryRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

/**
 * ADR-025 Task 15: the overlap query is human-source only — an agent wall-clock
 * entry never counts as a human double-booking.
 *
 * @internal
 *
 * @coversNothing
 */
final class OverlapSourceTest extends AbstractWebTestCase
{
    public function testFindOverlappingEntriesExcludesAgentSource(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entryRepository);

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');
        $day = '2099-11-11';

        // Both entries occupy 10:00-11:00 — a human and an agent overlap.
        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::HUMAN)->setDuration(60)
                ->setDay(new DateTime($day))->setStart(new DateTime('10:00'))->setEnd(new DateTime('11:00')),
        );
        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::AGENT)->setDuration(60)
                ->setDay(new DateTime($day))->setStart(new DateTime('10:00'))->setEnd(new DateTime('11:00')),
        );
        $entityManager->flush();

        $overlaps = $entryRepository->findOverlappingEntries($user, $day, '10:30', '10:45');

        self::assertCount(1, $overlaps, 'only the human entry counts as an overlap');
        self::assertSame(EntrySource::HUMAN, $overlaps[0]->getSource());
    }
}

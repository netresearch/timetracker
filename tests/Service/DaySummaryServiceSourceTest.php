<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service;

use App\Entity\Entry;
use App\Entity\User;
use App\Enum\EntrySource;
use App\Service\DaySummaryService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

/**
 * ADR-025 Task 11: day total counts human source only.
 *
 * @internal
 *
 * @coversNothing
 */
final class DaySummaryServiceSourceTest extends AbstractWebTestCase
{
    public function testDayTotalExcludesAgentMinutes(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $service = self::getContainer()->get(DaySummaryService::class);
        self::assertInstanceOf(DaySummaryService::class, $service);

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');
        $day = '2099-06-16';

        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::HUMAN)->setDuration(60)
                ->setDay(new DateTime($day))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00')),
        );
        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::AGENT)->setDuration(180)
                ->setDay(new DateTime($day))->setStart(new DateTime('10:00'))->setEnd(new DateTime('13:00')),
        );
        $entityManager->flush();

        $summary = $service->forUser($user, $day);

        self::assertSame(60, $summary->totalMinutes, 'agent 180 excluded from the human day total');
        self::assertCount(1, $summary->entries);
    }
}

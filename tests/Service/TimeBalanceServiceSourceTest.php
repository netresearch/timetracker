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
use App\Service\ClockInterface;
use App\Service\TimeBalanceService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

/**
 * ADR-025 Task 9: time-balance IST counts human source only.
 *
 * @internal
 *
 * @coversNothing
 */
final class TimeBalanceServiceSourceTest extends AbstractWebTestCase
{
    public function testIstCountsHumanSourceOnly(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $service = self::getContainer()->get(TimeBalanceService::class);
        self::assertInstanceOf(TimeBalanceService::class, $service);

        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $today = DateTime::createFromInterface($clock->today());

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');

        $baseIst = $service->forUser($user)->today->ist;

        // A human 8h day and an agent 8h day on the same date.
        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::HUMAN)->setDuration(480)
                ->setDay(clone $today)->setStart(new DateTime('08:00'))->setEnd(new DateTime('16:00')),
        );
        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::AGENT)->setDuration(480)
                ->setDay(clone $today)->setStart(new DateTime('16:00'))->setEnd(new DateTime('23:59')),
        );
        $entityManager->flush();

        // IST must rise by the human 480 only, not 960.
        self::assertSame($baseIst + 480, $service->forUser($user)->today->ist);
    }
}

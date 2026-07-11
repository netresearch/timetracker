<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\User;
use App\Enum\EntrySource;
use App\Repository\EntryRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

/**
 * ADR-025 Task 12: getEntrySummary counts human source only.
 *
 * @internal
 *
 * @coversNothing
 */
final class EntrySummarySourceTest extends AbstractWebTestCase
{
    public function testSummaryTotalsCountHumanSourceOnly(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entryRepository);

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');
        $userId = (int) $user->getId();

        // A fresh customer + unique ticket so only the two seeded entries match.
        $customer = new Customer()->setName('ADR-025 Source Co')->setActive(true)->setGlobal(false);
        $entityManager->persist($customer);
        $ticket = 'ADR025-SRC-1';

        $human = new Entry()->setUser($user)->setCustomer($customer)->setTicket($ticket)
            ->setSource(EntrySource::HUMAN)->setDuration(60)
            ->setDay(new DateTime('2099-06-17'))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00'));
        $agent = new Entry()->setUser($user)->setCustomer($customer)->setTicket($ticket)
            ->setSource(EntrySource::AGENT)->setDuration(180)
            ->setDay(new DateTime('2099-06-17'))->setStart(new DateTime('10:00'))->setEnd(new DateTime('13:00'));
        $entityManager->persist($human);
        $entityManager->persist($agent);
        $entityManager->flush();

        $summary = $entryRepository->getEntrySummary((int) $human->getId(), $userId, []);

        self::assertArrayHasKey('customer', $summary);
        self::assertSame(60, $summary['customer']['total'], 'customer total excludes agent minutes');
        self::assertSame(1, $summary['customer']['entries'], 'customer entry count excludes agent entry');
        self::assertSame(60, $summary['customer']['own']);

        self::assertArrayHasKey('ticket', $summary);
        self::assertSame(60, $summary['ticket']['total'], 'ticket total excludes agent minutes');
        self::assertSame(1, $summary['ticket']['entries']);
        self::assertSame(60, $summary['ticket']['own']);
    }
}

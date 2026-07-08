<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Traits;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManagerInterface;

use function assert;

/**
 * Persists a minimal time entry owned by a named fixture user (project /
 * customer / activity id 1), for tests that need an entry with a pinned
 * owner — e.g. owner-scoping (IDOR) and summary assertions.
 */
trait CreatesTestEntries
{
    protected function createEntryFor(string $username, string $ticket = 'SA-42', string $description = 'test entry'): Entry
    {
        $entityManager = $this->testEntityManager();

        $owner = $entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $owner);
        $project = $entityManager->getRepository(Project::class)->find(1);
        self::assertInstanceOf(Project::class, $project);
        $customer = $project->getCustomer();
        self::assertInstanceOf(Customer::class, $customer);
        $activity = $entityManager->getRepository(Activity::class)->find(1);
        self::assertInstanceOf(Activity::class, $activity);

        $entry = new Entry();
        $entry->setUser($owner)
            ->setCustomer($customer)
            ->setProject($project)
            ->setActivity($activity)
            ->setTicket($ticket)
            ->setDescription($description)
            ->setDay('2026-07-06')
            ->setStart('09:00:00')
            ->setEnd('10:00:00')
            ->setDuration(60);
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    protected function testEntityManager(): EntityManagerInterface
    {
        /** @var Registry $doctrine */
        $doctrine = static::getContainer()->get('doctrine');
        $entityManager = $doctrine->getManager();
        assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}

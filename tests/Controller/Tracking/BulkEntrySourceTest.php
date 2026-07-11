<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Tracking;

use App\Entity\Entry;
use App\Entity\User;
use App\Enum\EntrySource;
use App\Repository\EntryRepository;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function assert;

/**
 * ADR-025: the bulk day-break write path is the SaveEntryAction bypass. Its
 * entries are always human (server-set) and must carry the caller as loggedBy,
 * closing the "missed write path" attribution leak.
 *
 * @internal
 *
 * @coversNothing
 */
final class BulkEntrySourceTest extends AbstractWebTestCase
{
    public function testBulkEntriesAreHumanSourcedAndStampLoggedBy(): void
    {
        $this->logInSession('unittest');

        // Preset 1 (customer/project/activity 1). A single-day range keeps the
        // assertion tight; usecontract=0 uses the explicit start/end times.
        $this->client->request(Request::METHOD_POST, '/tracking/bulkentry', [
            'preset' => 1,
            'startdate' => '2024-03-11',
            'enddate' => '2024-03-11',
            'starttime' => '09:00:00',
            'endtime' => '10:00:00',
            'usecontract' => 0,
            'skipweekend' => 0,
            'skipholidays' => 0,
        ]);
        $this->assertStatusCode(200);

        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $manager = $doctrine->getManager();
        $manager->clear();
        $entryRepository = $manager->getRepository(Entry::class);
        assert($entryRepository instanceof EntryRepository);

        $entries = $entryRepository->findBy(['user' => 1, 'day' => '2024-03-11']);
        self::assertNotEmpty($entries, 'bulk entry should have created at least one entry');

        foreach ($entries as $entry) {
            self::assertSame(EntrySource::HUMAN, $entry->getSource());
            self::assertFalse($entry->isEstimated());
            self::assertInstanceOf(User::class, $entry->getLoggedBy());
            self::assertSame(1, $entry->getLoggedBy()->getId());
        }
    }
}

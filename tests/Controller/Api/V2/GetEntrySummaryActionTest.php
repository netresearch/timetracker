<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Api\V2;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function assert;
use function is_array;
use function json_decode;
use function sprintf;

/**
 * GET /api/v2/entries/{id}/summary (ADR-022): the "Info" popup aggregation,
 * owner-scoped — a foreign or unknown entry id reads as 404.
 *
 * @internal
 */
final class GetEntrySummaryActionTest extends AbstractWebTestCase
{
    public function testOwnEntryReturnsScopesAndEstimate(): void
    {
        $entryId = $this->createEntryFor('unittest');

        $this->client->request(Request::METHOD_GET, sprintf('/api/v2/entries/%d/summary', $entryId));
        $this->assertStatusCode(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        foreach (['customer', 'project', 'activity', 'ticket'] as $scope) {
            self::assertArrayHasKey($scope, $data);
            assert(is_array($data[$scope]));
            foreach (['scope', 'name', 'entries', 'total', 'own', 'estimation'] as $key) {
                self::assertArrayHasKey($key, $data[$scope], $scope);
            }
        }
        self::assertArrayHasKey('estimate', $data);
        assert(is_array($data['estimate']));
        self::assertArrayHasKey('status', $data['estimate']);
        self::assertArrayHasKey('warnings', $data);
    }

    public function testForeignEntryReadsAsNotFound(): void
    {
        // Owned by user 'developer' (id 2), requested by the session user
        // 'unittest' (id 1) — must be 404, not a cross-user disclosure (IDOR).
        $entryId = $this->createEntryFor('developer');

        $this->client->request(Request::METHOD_GET, sprintf('/api/v2/entries/%d/summary', $entryId));

        $this->assertStatusCode(404);
    }

    public function testUnknownEntryIsNotFound(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/entries/999999/summary');

        $this->assertStatusCode(404);
    }

    private function createEntryFor(string $username): int
    {
        /** @var Registry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $entityManager = $doctrine->getManager();
        assert($entityManager instanceof EntityManagerInterface);

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
            ->setTicket('SA-42')
            ->setDescription('summary test entry')
            ->setDay('2026-07-06')
            ->setStart('09:00:00')
            ->setEnd('10:00:00')
            ->setDuration(60);
        $entityManager->persist($entry);
        $entityManager->flush();

        return (int) $entry->getId();
    }
}

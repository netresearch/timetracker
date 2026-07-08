<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function assert;
use function is_array;
use function json_decode;

/**
 * POST /getSummary — deprecated v1 endpoint (ADR-022): quota formatting and
 * the backported owner scoping (a foreign entry id reads as 404).
 *
 * @internal
 *
 * @coversNothing
 */
final class DefaultControllerSummaryTest extends AbstractWebTestCase
{
    public function testGetSummaryActionWithProjectEstimationComputesQuota(): void
    {
        $entry = $this->createEntryFor('unittest');
        $project = $entry->getProject();
        self::assertNotNull($project, 'Project should not be null');
        // Ensure estimation is set to a non-zero value
        $project->setEstimation(300);
        $this->entityManager()->flush();

        $this->client->request(Request::METHOD_POST, '/getSummary', ['id' => $entry->getId()]);
        $this->assertStatusCode(200);

        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertArrayHasKey('project', $response);
        assert(is_array($response['project']));
        self::assertArrayHasKey('quota', $response['project']);
        $quota = $response['project']['quota'];
        // When estimation is set, quota should be a percentage string
        self::assertIsString($quota);
        self::assertStringEndsWith('%', $quota);
        // Deprecated endpoint (ADR-022 §5) — consumers see it at call time.
        self::assertSame('true', $this->client->getResponse()->headers->get('Deprecation'));
    }

    public function testGetSummaryActionWithoutEstimationLeavesZeroQuota(): void
    {
        $entry = $this->createEntryFor('unittest');
        $project = $entry->getProject();
        self::assertNotNull($project, 'Project should not be null');
        // Remove estimation (set to 0)
        $project->setEstimation(0);
        $this->entityManager()->flush();

        $this->client->request(Request::METHOD_POST, '/getSummary', ['id' => $entry->getId()]);
        $this->assertStatusCode(200);

        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertArrayHasKey('project', $response);
        assert(is_array($response['project']));
        // Without estimation set, quota remains numeric zero according to default data
        self::assertSame(0, $response['project']['quota'] ?? 0);
    }

    public function testGetSummaryActionForeignEntryReadsAsNotFound(): void
    {
        // Owned by 'developer' (id 2), requested by the session user 'unittest'
        // (id 1): the ADR-022 §5 backport scopes v1 to the caller's own entries.
        $entry = $this->createEntryFor('developer');

        $this->client->request(Request::METHOD_POST, '/getSummary', ['id' => $entry->getId()]);

        $this->assertStatusCode(404);
    }

    private function createEntryFor(string $username): Entry
    {
        $entityManager = $this->entityManager();

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
            ->setTicket('SA-21')
            ->setDescription('summary quota test entry')
            ->setDay('2026-07-06')
            ->setStart('09:00:00')
            ->setEnd('10:00:00')
            ->setDuration(60);
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $this->client->getContainer()->get('doctrine');
        $entityManager = $doctrine->getManager();
        assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}

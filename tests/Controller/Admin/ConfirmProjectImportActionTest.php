<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Admin;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\TicketSystem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function assert;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * POST /project-import/confirm (ADR-026 P1): create/link Project + Customer per
 * confirmed row, with find-by-name customer reuse, idempotent existing-project
 * handling, and validation rejections.
 *
 * @internal
 *
 * @coversNothing
 */
final class ConfirmProjectImportActionTest extends AbstractWebTestCase
{
    private const string URI = '/project-import/confirm';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
    }

    public function testCreatesProjectAndCustomerByName(): void
    {
        $this->post(['rows' => [[
            'jira_key' => 'NEWP',
            'project_name' => 'New Project',
            'ticket_system_id' => 1,
            'customer_name' => 'Brand New Customer',
        ]]]);
        $this->assertStatusCode(200);

        $data = $this->responseData();
        self::assertCount(1, $data['projects']);
        $project = $data['projects'][0];
        self::assertSame('created', $project['status']);
        self::assertSame('NEWP', $project['jira_key']);
        self::assertSame('New Project', $project['project_name']);
        self::assertSame('Brand New Customer', $project['customer_name']);
        self::assertIsInt($project['project_id']);
        self::assertIsInt($project['customer_id']);

        $this->entityManager->clear();
        $persisted = $this->entityManager->getRepository(Project::class)->findOneBy(['jiraId' => 'NEWP']);
        self::assertInstanceOf(Project::class, $persisted);
        self::assertSame('New Project', $persisted->getName());
        self::assertSame('Brand New Customer', $persisted->getCustomer()?->getName());
    }

    public function testFindsCustomerByNameWithoutDuplicating(): void
    {
        // Two rows naming the SAME new customer must create exactly ONE customer.
        $this->post(['rows' => [
            ['jira_key' => 'AAA', 'project_name' => 'A', 'ticket_system_id' => 1, 'customer_name' => 'Shared Cust'],
            ['jira_key' => 'BBB', 'project_name' => 'B', 'ticket_system_id' => 1, 'customer_name' => 'Shared Cust'],
        ]]);
        $this->assertStatusCode(200);

        $data = $this->responseData();
        self::assertCount(2, $data['projects']);
        self::assertSame($data['projects'][0]['customer_id'], $data['projects'][1]['customer_id']);

        $this->entityManager->clear();
        $customers = $this->entityManager->getRepository(Customer::class)->findBy(['name' => 'Shared Cust']);
        self::assertCount(1, $customers);
    }

    public function testReusesExistingFixtureCustomerByName(): void
    {
        // 'Der Bäcker von nebenan' is fixture customer 1 — a by-name row must
        // link to it, not create a second customer of that name.
        $this->post(['rows' => [[
            'jira_key' => 'CCC',
            'project_name' => 'C',
            'ticket_system_id' => 1,
            'customer_name' => 'Der Bäcker von nebenan',
        ]]]);
        $this->assertStatusCode(200);

        self::assertSame(1, $this->responseData()['projects'][0]['customer_id']);

        $this->entityManager->clear();
        $customers = $this->entityManager->getRepository(Customer::class)->findBy(['name' => 'Der Bäcker von nebenan']);
        self::assertCount(1, $customers);
    }

    public function testOverrideByExistingCustomerId(): void
    {
        $this->post(['rows' => [[
            'jira_key' => 'OVR',
            'project_name' => 'Override',
            'ticket_system_id' => 1,
            'customer_id' => 2,
        ]]]);
        $this->assertStatusCode(200);

        self::assertSame(2, $this->responseData()['projects'][0]['customer_id']);
    }

    public function testIdempotentExistingProjectIsLinkedNotDuplicated(): void
    {
        $ticketSystem = $this->entityManager->find(TicketSystem::class, 1);
        assert($ticketSystem instanceof TicketSystem);
        $customer = $this->entityManager->find(Customer::class, 1);
        assert($customer instanceof Customer);

        $existing = new Project();
        $existing->setName('Already Here')
            ->setCustomer($customer)
            ->setJiraId('EXIST')
            ->setTicketSystem($ticketSystem)
            ->setActive(true)
            ->setGlobal(false)
            ->setEstimation(0);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();
        $existingId = $existing->getId();

        $this->post(['rows' => [[
            'jira_key' => 'EXIST',
            'project_name' => 'Ignored New Name',
            'ticket_system_id' => 1,
            'customer_name' => 'Ignored Customer',
        ]]]);
        $this->assertStatusCode(200);

        $project = $this->responseData()['projects'][0];
        self::assertSame('existing', $project['status']);
        self::assertSame($existingId, $project['project_id']);
        self::assertSame('Already Here', $project['project_name']);

        // No second project claimed the prefix, and no bogus customer was made.
        $this->entityManager->clear();
        $projects = $this->entityManager->getRepository(Project::class)->findBy(['jiraId' => 'EXIST', 'ticketSystem' => 1]);
        self::assertCount(1, $projects);
        self::assertCount(0, $this->entityManager->getRepository(Customer::class)->findBy(['name' => 'Ignored Customer']));
    }

    public function testSameTempoKeyCreatesCustomerOnceAcrossConfirms(): void
    {
        // ADR-026 P2: two SEPARATE confirms of the same Tempo customer (same
        // stable key) — even with a drifted name — must resolve to ONE customer.
        $this->post(['rows' => [[
            'jira_key' => 'TKA',
            'project_name' => 'Tempo Alpha',
            'ticket_system_id' => 1,
            'customer_name' => 'Deutsche Post AG',
            'customer_key' => 'DP',
        ]]]);
        $this->assertStatusCode(200);
        $firstCustomerId = $this->responseData()['projects'][0]['customer_id'];
        self::assertIsInt($firstCustomerId);

        // Second run: SAME key, DRIFTED name -> reuse the keyed customer.
        $this->post(['rows' => [[
            'jira_key' => 'TKB',
            'project_name' => 'Tempo Beta',
            'ticket_system_id' => 1,
            'customer_name' => 'Deutsche Post',
            'customer_key' => 'DP',
        ]]]);
        $this->assertStatusCode(200);
        self::assertSame($firstCustomerId, $this->responseData()['projects'][0]['customer_id']);

        $this->entityManager->clear();
        $byKey = $this->entityManager->getRepository(Customer::class)->findBy(['tempoCustomerKey' => 'DP']);
        self::assertCount(1, $byKey);
        // The first name stands; the drifted name did not rename or duplicate it.
        self::assertSame('Deutsche Post AG', $byKey[0]->getName());
    }

    public function testSameTempoKeyTwiceInOneBatchDoesNotDuplicate(): void
    {
        // Two rows in ONE batch sharing a key but naming it differently: one customer.
        $this->post(['rows' => [
            ['jira_key' => 'BKA', 'project_name' => 'A', 'ticket_system_id' => 1, 'customer_name' => 'Acme Anvils', 'customer_key' => 'ACM'],
            ['jira_key' => 'BKB', 'project_name' => 'B', 'ticket_system_id' => 1, 'customer_name' => 'Acme Corp', 'customer_key' => 'ACM'],
        ]]);
        $this->assertStatusCode(200);

        $data = $this->responseData();
        self::assertCount(2, $data['projects']);
        self::assertSame($data['projects'][0]['customer_id'], $data['projects'][1]['customer_id']);

        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(Customer::class)->findBy(['tempoCustomerKey' => 'ACM']));
    }

    public function testNameMatchBackfillsTempoKey(): void
    {
        // A by-name match onto an existing key-less customer stamps the key so a
        // later run resolves it by key. Fixture customer 1 has no Tempo key.
        $this->post(['rows' => [[
            'jira_key' => 'BFL',
            'project_name' => 'Backfill',
            'ticket_system_id' => 1,
            'customer_name' => 'Der Bäcker von nebenan',
            'customer_key' => 'BAK',
        ]]]);
        $this->assertStatusCode(200);
        self::assertSame(1, $this->responseData()['projects'][0]['customer_id']);

        $this->entityManager->clear();
        $customer = $this->entityManager->find(Customer::class, 1);
        self::assertInstanceOf(Customer::class, $customer);
        self::assertSame('BAK', $customer->getTempoCustomerKey());
        // No second customer was created for that name.
        self::assertCount(1, $this->entityManager->getRepository(Customer::class)->findBy(['name' => 'Der Bäcker von nebenan']));
    }

    public function testCustomerIdOverrideKeepsIdentityIgnoringTempoKey(): void
    {
        // An explicit existing-customer pick keeps its own identity: a stray
        // customer_key must NOT be stamped onto it (fixture customer 2 stays key-less).
        $this->post(['rows' => [[
            'jira_key' => 'OVK',
            'project_name' => 'Override Keyed',
            'ticket_system_id' => 1,
            'customer_id' => 2,
            'customer_key' => 'SHOULDNOTAPPLY',
        ]]]);
        $this->assertStatusCode(200);
        self::assertSame(2, $this->responseData()['projects'][0]['customer_id']);

        $this->entityManager->clear();
        $customer = $this->entityManager->find(Customer::class, 2);
        self::assertInstanceOf(Customer::class, $customer);
        self::assertNull($customer->getTempoCustomerKey());
    }

    public function testBlankCustomerNameWithoutIdRejected(): void
    {
        $this->post(['rows' => [[
            'jira_key' => 'ZZZ',
            'project_name' => 'Z',
            'ticket_system_id' => 1,
            'customer_name' => '   ',
        ]]]);
        $this->assertStatusCode(422);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Project::class)->findOneBy(['jiraId' => 'ZZZ']));
    }

    public function testUnknownCustomerIdRejected(): void
    {
        $this->post(['rows' => [[
            'jira_key' => 'YYY',
            'project_name' => 'Y',
            'ticket_system_id' => 1,
            'customer_id' => 999999,
        ]]]);
        $this->assertStatusCode(422);
    }

    public function testInvalidJiraPrefixRejected(): void
    {
        $this->post(['rows' => [[
            'jira_key' => 'AA, BB',
            'project_name' => 'X',
            'ticket_system_id' => 1,
            'customer_name' => 'Cust',
        ]]]);
        $this->assertStatusCode(422);
    }

    public function testEmptyRowsRejected(): void
    {
        $this->post(['rows' => []]);
        $this->assertStatusCode(422);
    }

    public function testUnknownTicketSystemRejected(): void
    {
        $this->post(['rows' => [[
            'jira_key' => 'TSX',
            'project_name' => 'T',
            'ticket_system_id' => 99999,
            'customer_name' => 'Cust',
        ]]]);
        $this->assertStatusCode(422);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(array $payload): void
    {
        $this->client->request(
            Request::METHOD_POST,
            self::URI,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{projects: list<array<string, mixed>>}
     */
    private function responseData(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('projects', $data);
        self::assertIsList($data['projects']);

        /** @var array{projects: list<array<string, mixed>>} $data */
        return $data;
    }
}

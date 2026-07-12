<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Admin;

use App\Entity\PersonioConfig;
use App\Entity\User;
use App\Service\Personio\PersonioClient;
use App\Service\Personio\PersonioClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function assert;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * GET /personio/employee-matches and POST /personio/employee-matches/confirm
 * (ADR-024 P3). The Personio client is stubbed in the container; fixture user
 * 'developer' (id 2) starts without an employee id.
 *
 * @internal
 *
 * @coversNothing
 */
final class PersonioEmployeeMatchActionsTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
    }

    public function testProposalsMatchByEmailLocalpart(): void
    {
        $this->seedActiveConfig();
        $this->stubPersons([
            ['id' => '900', 'first_name' => 'Dev', 'last_name' => 'Eloper', 'email' => 'developer@netresearch.de'],
        ]);

        $this->client->request(Request::METHOD_GET, '/personio/employee-matches', [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $proposals = $this->responseData()['proposals'] ?? null;
        self::assertIsArray($proposals);
        $developer = null;
        foreach ($proposals as $proposal) {
            self::assertIsArray($proposal);
            if ('developer' === ($proposal['username'] ?? null)) {
                $developer = $proposal;
            }
        }

        self::assertIsArray($developer);
        self::assertSame('900', $developer['person_id']);
        self::assertSame('email', $developer['source']);
    }

    public function testNoActiveConfigReturns422(): void
    {
        $this->client->request(Request::METHOD_GET, '/personio/employee-matches', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(422);
    }

    public function testConfirmAppliesEmployeeId(): void
    {
        $this->post(['matches' => [['user_id' => 2, 'person_id' => '900']]]);
        $this->assertStatusCode(200);

        $applied = $this->responseData()['applied'] ?? null;
        self::assertIsArray($applied);
        self::assertCount(1, $applied);

        $this->entityManager->clear();
        $developer = $this->entityManager->find(User::class, 2);
        assert($developer instanceof User);
        self::assertSame(900, $developer->getPersonioEmployeeId());
    }

    public function testConfirmSkipsNonNumericPersonId(): void
    {
        $this->post(['matches' => [['user_id' => 2, 'person_id' => 'not-numeric']]]);
        $this->assertStatusCode(200);

        $applied = $this->responseData()['applied'] ?? null;
        self::assertIsArray($applied);
        self::assertCount(0, $applied);

        $this->entityManager->clear();
        $developer = $this->entityManager->find(User::class, 2);
        assert($developer instanceof User);
        self::assertNull($developer->getPersonioEmployeeId());
    }

    private function seedActiveConfig(): void
    {
        $config = new PersonioConfig();
        $config->setName('Test Personio')
            ->setBaseUrl('https://api.personio.test')
            ->setClientId('cid')
            ->setClientSecret('secret')
            ->setActive(true);
        $this->entityManager->persist($config);
        $this->entityManager->flush();
    }

    /**
     * @param list<array{id: string, first_name: ?string, last_name: ?string, email: ?string}> $persons
     */
    private function stubPersons(array $persons): void
    {
        $client = self::createStub(PersonioClient::class);
        $client->method('listPersons')->willReturn($persons);

        $factory = self::createStub(PersonioClientFactory::class);
        $factory->method('create')->willReturn($client);
        self::getContainer()->set(PersonioClientFactory::class, $factory);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(array $payload): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/personio/employee-matches/confirm',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function responseData(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }
}

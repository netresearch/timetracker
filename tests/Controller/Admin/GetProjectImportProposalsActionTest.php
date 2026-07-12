<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Admin;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\SyncRun;
use App\Entity\SyncRunItem;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Exception\Integration\Jira\JiraApiException;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Security\TokenEncryptionService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Tests\AbstractWebTestCase;

use function assert;
use function in_array;
use function json_decode;

/**
 * GET /project-import/proposals (ADR-026 P1). The Jira/Tempo boundary (the
 * OAuth api factory) is stubbed in the container — the real derivation service
 * runs against canned responses, exercising the pipeline (unresolved prefixes
 * -> proposals), the snake_case JSON shape, the per-key error row, and
 * validation. No live Jira is hit.
 *
 * @internal
 *
 * @coversNothing
 */
final class GetProjectImportProposalsActionTest extends AbstractWebTestCase
{
    private const string URI = '/project-import/proposals';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
    }

    public function testReturnsProposalsForUnresolvedPrefixesWithPerKeyTolerance(): void
    {
        $this->seedUnresolved(['SRVMO-1', 'SRVMO-2', 'JOT-9']);

        // JOT -> category; SRVMO throws inside Jira -> error row (the batch must
        // not abort). Prefixes reach the service distinct and sorted: JOT, SRVMO.
        $this->stubApiFactory(
            projectInfo: [
                'JOT' => ['id' => 23050, 'name' => 'Job Tool', 'categoryName' => 'NR: IT'],
            ],
            throwKeys: ['SRVMO'],
            tenant: ['/rest/tempo-accounts/1/account/project/23050' => []],
        );

        $this->get(self::URI . '?ticketSystem=1');
        $this->assertStatusCode(200);

        $data = $this->responseData();
        self::assertSame(1, $data['ticket_system_id']);
        self::assertCount(2, $data['proposals']);

        $category = $data['proposals'][0];
        self::assertIsArray($category);
        self::assertSame('JOT', $category['jira_key']);
        self::assertSame('JOT', $category['jira_id_prefix']);
        self::assertSame(23050, $category['project_id']);
        self::assertSame('Job Tool', $category['project_name']);
        self::assertSame('NR: IT', $category['derived_customer_name']);
        self::assertNull($category['derived_customer_key']);
        self::assertSame('category', $category['derivation_source']);
        self::assertSame([], $category['candidate_customers']);

        $error = $data['proposals'][1];
        self::assertIsArray($error);
        self::assertSame('SRVMO', $error['jira_key']);
        self::assertSame('error', $error['derivation_source']);
        self::assertNull($error['derived_customer_name']);
    }

    public function testExcludesPrefixesAlreadyOwnedByAProject(): void
    {
        // SRVMO and JOT are both parked, but SRVMO was already imported (a
        // project now owns it on this ticket system). The review must stop
        // re-proposing SRVMO — and never call Jira/Tempo for it.
        $this->seedUnresolved(['SRVMO-1', 'JOT-9']);
        $this->seedProjectOwning('SRVMO');

        $this->stubApiFactory(
            projectInfo: [
                'JOT' => ['id' => 23050, 'name' => 'Job Tool', 'categoryName' => 'NR: IT'],
            ],
            // SRVMO would throw if it reached the service — it must not.
            throwKeys: ['SRVMO'],
            tenant: ['/rest/tempo-accounts/1/account/project/23050' => []],
        );

        $this->get(self::URI . '?ticketSystem=1');
        $this->assertStatusCode(200);

        $data = $this->responseData();
        self::assertCount(1, $data['proposals']);

        $only = $data['proposals'][0];
        self::assertIsArray($only);
        self::assertSame('JOT', $only['jira_key']);
        self::assertSame('category', $only['derivation_source']);
    }

    public function testEmptyProposalsWhenNothingParked(): void
    {
        $this->stubApiFactory([], [], []);

        $this->get(self::URI . '?ticketSystem=1');
        $this->assertStatusCode(200);

        self::assertSame([], $this->responseData()['proposals']);
    }

    public function testUnknownTicketSystem404(): void
    {
        $this->get(self::URI . '?ticketSystem=99999');
        $this->assertStatusCode(404);
    }

    public function testMissingTicketSystem422(): void
    {
        $this->get(self::URI);
        $this->assertStatusCode(422);
    }

    private function get(string $uri): void
    {
        $this->client->request(Request::METHOD_GET, $uri, [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * @param array<string, array{id: int, name: string, categoryName: string|null}> $projectInfo
     * @param list<string>                                                           $throwKeys
     * @param array<string, mixed>                                                   $tenant
     */
    private function stubApiFactory(array $projectInfo, array $throwKeys, array $tenant): void
    {
        $api = $this->cannedApi($projectInfo, $throwKeys, $tenant);

        $factory = self::createStub(JiraOAuthApiFactory::class);
        $factory->method('create')->willReturn($api);
        self::getContainer()->set(JiraOAuthApiFactory::class, $factory);
    }

    /**
     * @param array<string, array{id: int, name: string, categoryName: string|null}> $projectInfo
     * @param list<string>                                                           $throwKeys
     * @param array<string, mixed>                                                   $tenant
     */
    private function cannedApi(array $projectInfo, array $throwKeys, array $tenant): JiraOAuthApiService
    {
        $tokenEncryptionService = self::getContainer()->get(TokenEncryptionService::class);
        assert($tokenEncryptionService instanceof TokenEncryptionService);

        return new class(self::createStub(User::class), self::createStub(TicketSystem::class), self::createStub(ManagerRegistry::class), self::createStub(RouterInterface::class), $tokenEncryptionService, $projectInfo, $throwKeys, $tenant) extends JiraOAuthApiService {
            /**
             * @param array<string, array{id: int, name: string, categoryName: string|null}> $projectInfo
             * @param list<string>                                                           $throwKeys
             * @param array<string, mixed>                                                   $tenant
             */
            public function __construct(
                User $user,
                TicketSystem $ticketSystem,
                ManagerRegistry $managerRegistry,
                RouterInterface $router,
                TokenEncryptionService $tokenEncryptionService,
                private readonly array $projectInfo,
                private readonly array $throwKeys,
                private readonly array $tenant,
            ) {
                parent::__construct($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService);
            }

            public function getProjectInfo(string $key): ?array
            {
                if (in_array($key, $this->throwKeys, true)) {
                    throw new JiraApiException('boom', 500);
                }

                return $this->projectInfo[$key] ?? null;
            }

            public function getFromTenant(string $absolutePath): mixed
            {
                return $this->tenant[$absolutePath] ?? [];
            }
        };
    }

    /**
     * @return array{ticket_system_id: int, proposals: list<mixed>}
     */
    private function responseData(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('ticket_system_id', $data);
        self::assertIsInt($data['ticket_system_id']);
        self::assertArrayHasKey('proposals', $data);
        self::assertIsList($data['proposals']);

        /** @var array{ticket_system_id: int, proposals: list<mixed>} $data */
        return $data;
    }

    /**
     * @param list<string> $issueKeys
     */
    private function seedUnresolved(array $issueKeys): void
    {
        $ticketSystem = $this->entityManager->find(TicketSystem::class, 1);
        assert($ticketSystem instanceof TicketSystem);
        $admin = $this->entityManager->find(User::class, 1);
        assert($admin instanceof User);

        $run = new SyncRun()
            ->setType(SyncRunType::IMPORT)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setTicketSystem($ticketSystem)
            ->setTriggeredBy($admin)
            ->setScope([])
            ->setCounters([])
            ->setStartedAt(new DateTimeImmutable('2026-07-05 10:00:00'));
        $this->entityManager->persist($run);

        foreach ($issueKeys as $issueKey) {
            $item = new SyncRunItem()
                ->setSyncRun($run)
                ->setKind(SyncItemKind::UNRESOLVED_PROJECT)
                ->setIssueKey($issueKey)
                ->setCreatedAt(new DateTimeImmutable('2026-07-05 10:00:00'));
            $this->entityManager->persist($item);
        }

        $this->entityManager->flush();
    }

    /**
     * Give ticket system 1 a project that already claims $prefix as its jira_id —
     * the "already imported" state that must exclude the prefix from the review.
     */
    private function seedProjectOwning(string $prefix): void
    {
        $ticketSystem = $this->entityManager->find(TicketSystem::class, 1);
        assert($ticketSystem instanceof TicketSystem);

        $customer = new Customer()->setName('Owned Co')->setActive(true)->setGlobal(false);
        $this->entityManager->persist($customer);

        $project = new Project()
            ->setName('Already Imported')
            ->setCustomer($customer)
            ->setJiraId($prefix)
            ->setTicketSystem($ticketSystem)
            ->setActive(true)
            ->setGlobal(false)
            ->setEstimation(0);
        $this->entityManager->persist($project);

        $this->entityManager->flush();
    }
}

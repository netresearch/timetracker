<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\DTO\Sync\ProjectImportProposal;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Security\TokenEncryptionService;
use App\Service\Sync\ProjectImportProposalService;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;
use Tests\Traits\TokenEncryptionTestTrait;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Unit tests for the ADR-026 P1a derivation service, driven by the four
 * live-verified NR-JIRA cases (SRVMO/SRVACME=tempo, JOT=category,
 * NRFE=ambiguous) plus a tempo-default and a not-a-project case.
 *
 * @internal
 */
#[CoversClass(ProjectImportProposalService::class)]
#[CoversClass(ProjectImportProposal::class)]
final class ProjectImportProposalServiceTest extends TestCase
{
    use TokenEncryptionTestTrait;

    /**
     * @param array<string, array{id: int, name: string, categoryName: string|null}|null> $projectInfo key => getProjectInfo result
     * @param array<string, mixed>                                                        $tenant      absolute path => decoded body
     */
    private function service(array $projectInfo, array $tenant): ProjectImportProposalService
    {
        $api = $this->cannedApi($projectInfo, $tenant);

        $factory = self::createStub(JiraOAuthApiFactory::class);
        $factory->method('create')->willReturn($api);

        return new ProjectImportProposalService($factory);
    }

    /**
     * @param array<string, array{id: int, name: string, categoryName: string|null}|null> $projectInfo
     * @param array<string, mixed>                                                        $tenant
     */
    private function cannedApi(array $projectInfo, array $tenant): JiraOAuthApiService
    {
        $user = self::createStub(User::class);
        $ticketSystem = self::createStub(TicketSystem::class);
        $managerRegistry = self::createStub(ManagerRegistry::class);
        $router = self::createStub(RouterInterface::class);
        $tokenEncryptionService = $this->createTokenEncryptionService();

        return new class($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService, $projectInfo, $tenant) extends JiraOAuthApiService {
            /**
             * @param array<string, array{id: int, name: string, categoryName: string|null}|null> $projectInfo
             * @param array<string, mixed>                                                        $tenant
             */
            public function __construct(
                User $user,
                TicketSystem $ticketSystem,
                ManagerRegistry $managerRegistry,
                RouterInterface $router,
                TokenEncryptionService $tokenEncryptionService,
                private readonly array $projectInfo,
                private readonly array $tenant,
            ) {
                parent::__construct($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService);
            }

            public function getProjectInfo(string $key): ?array
            {
                return $this->projectInfo[$key] ?? null;
            }

            public function getFromTenant(string $absolutePath): mixed
            {
                return $this->tenant[$absolutePath] ?? [];
            }
        };
    }

    private static function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<ProjectImportProposal>
     */
    private function propose(ProjectImportProposalService $service, string ...$keys): array
    {
        return $service->proposeForKeys(array_values($keys), self::createStub(TicketSystem::class), self::createStub(User::class));
    }

    public function testSingleTempoCustomerResolvesToTempo(): void
    {
        // SRVMO(id 20350): 1 account -> customer Netresearch[NR]
        $service = $this->service(
            ['SRVMO' => ['id' => 20350, 'name' => 'Service Monitoring', 'categoryName' => 'NR: IT Services']],
            [
                '/rest/tempo-accounts/1/account/project/20350' => self::decode(
                    '[{"id":100,"key":"NRIT","name":"NR IT","status":"OPEN",'
                    . '"customer":{"id":9,"key":"NR","name":"Netresearch"}}]',
                ),
            ],
        );

        $proposal = $this->propose($service, 'SRVMO')[0];

        self::assertSame(ProjectImportProposal::SOURCE_TEMPO, $proposal->derivationSource);
        self::assertSame('Netresearch', $proposal->derivedCustomerName);
        self::assertSame('NR', $proposal->derivedCustomerKey);
        self::assertSame(20350, $proposal->projectId);
        self::assertSame('Service Monitoring', $proposal->projectName);
        self::assertSame('SRVMO', $proposal->jiraIdPrefix);
        self::assertSame([], $proposal->candidateCustomers);
    }

    public function testSecondSingleCustomerProjectAlsoResolvesToTempo(): void
    {
        // SRVACME(id 17250): 1 account -> Netresearch[NR]
        $service = $this->service(
            ['SRVACME' => ['id' => 17250, 'name' => 'ACME Service', 'categoryName' => 'NR: IT Services']],
            [
                '/rest/tempo-accounts/1/account/project/17250' => self::decode(
                    '[{"id":100,"key":"NRIT","name":"NR IT","customer":{"id":9,"key":"NR","name":"Netresearch"}}]',
                ),
            ],
        );

        $proposal = $this->propose($service, 'SRVACME')[0];

        self::assertSame(ProjectImportProposal::SOURCE_TEMPO, $proposal->derivationSource);
        self::assertSame('NR', $proposal->derivedCustomerKey);
    }

    public function testNoAccountsFallsBackToCategory(): void
    {
        // JOT(id 23050): 0 accounts, projectCategory "NR: IT"
        $service = $this->service(
            ['JOT' => ['id' => 23050, 'name' => 'Job Tool', 'categoryName' => 'NR: IT']],
            ['/rest/tempo-accounts/1/account/project/23050' => self::decode('[]')],
        );

        $proposal = $this->propose($service, 'JOT')[0];

        self::assertSame(ProjectImportProposal::SOURCE_CATEGORY, $proposal->derivationSource);
        self::assertSame('NR: IT', $proposal->derivedCustomerName);
        self::assertNull($proposal->derivedCustomerKey);
    }

    public function testSeveralCustomersWithNoDefaultParksAsAmbiguous(): void
    {
        // NRFE(id 10212): 4 accounts across 2 customers, no default link
        $service = $this->service(
            ['NRFE' => ['id' => 10212, 'name' => 'Frontend', 'categoryName' => 'NR: IT']],
            [
                '/rest/tempo-accounts/1/account/project/10212' => self::decode(
                    '[{"id":100,"key":"A","name":"A","customer":{"id":9,"key":"NR","name":"Netresearch"}},'
                    . '{"id":101,"key":"B","name":"B","customer":{"id":9,"key":"NR","name":"Netresearch"}},'
                    . '{"id":102,"key":"C","name":"C","customer":{"id":12,"key":"NRSO","name":"Netresearch Solutions"}},'
                    . '{"id":103,"key":"D","name":"D","customer":{"id":12,"key":"NRSO","name":"Netresearch Solutions"}}]',
                ),
                '/rest/tempo-accounts/1/link/project/10212' => self::decode(
                    '[{"id":1,"accountId":100,"defaultAccount":false},'
                    . '{"id":2,"accountId":102,"defaultAccount":false}]',
                ),
            ],
        );

        $proposal = $this->propose($service, 'NRFE')[0];

        self::assertSame(ProjectImportProposal::SOURCE_AMBIGUOUS, $proposal->derivationSource);
        self::assertNull($proposal->derivedCustomerName);
        self::assertNull($proposal->derivedCustomerKey);
        self::assertSame(['Netresearch [NR]', 'Netresearch Solutions [NRSO]'], $proposal->candidateCustomers);
    }

    public function testSeveralCustomersWithSingleDefaultResolvesToTempoDefault(): void
    {
        $service = $this->service(
            ['NRFE' => ['id' => 10212, 'name' => 'Frontend', 'categoryName' => 'NR: IT']],
            [
                '/rest/tempo-accounts/1/account/project/10212' => self::decode(
                    '[{"id":100,"key":"A","name":"A","customer":{"id":9,"key":"NR","name":"Netresearch"}},'
                    . '{"id":102,"key":"C","name":"C","customer":{"id":12,"key":"NRSO","name":"Netresearch Solutions"}}]',
                ),
                '/rest/tempo-accounts/1/link/project/10212' => self::decode(
                    '[{"id":1,"accountId":100,"defaultAccount":false},'
                    . '{"id":2,"accountId":102,"defaultAccount":true}]',
                ),
            ],
        );

        $proposal = $this->propose($service, 'NRFE')[0];

        self::assertSame(ProjectImportProposal::SOURCE_TEMPO_DEFAULT, $proposal->derivationSource);
        self::assertSame('Netresearch Solutions', $proposal->derivedCustomerName);
        self::assertSame('NRSO', $proposal->derivedCustomerKey);
    }

    public function testUnknownKeyIsNotAProject(): void
    {
        $service = $this->service(['KNOWN' => ['id' => 1, 'name' => 'x', 'categoryName' => null]], []);

        $proposal = $this->propose($service, 'NOPE')[0];

        self::assertSame(ProjectImportProposal::SOURCE_NOT_A_PROJECT, $proposal->derivationSource);
        self::assertNull($proposal->projectId);
        self::assertSame('NOPE', $proposal->jiraKey);
        self::assertSame('NOPE', $proposal->jiraIdPrefix);
    }

    public function testNoCustomerAndNoCategoryIsNone(): void
    {
        $service = $this->service(
            ['BARE' => ['id' => 5, 'name' => 'Bare', 'categoryName' => null]],
            ['/rest/tempo-accounts/1/account/project/5' => self::decode('[]')],
        );

        $proposal = $this->propose($service, 'BARE')[0];

        self::assertSame(ProjectImportProposal::SOURCE_NONE, $proposal->derivationSource);
        self::assertNull($proposal->derivedCustomerName);
    }
}

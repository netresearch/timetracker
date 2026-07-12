<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Integration\Jira\TempoClient;
use App\Service\Security\TokenEncryptionService;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;
use Tests\Traits\TokenEncryptionTestTrait;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Unit tests for the ADR-026 read-only Tempo client, fixtured on the exact
 * live NR-JIRA response shapes verified 2026-07-12.
 *
 * @internal
 */
#[CoversClass(TempoClient::class)]
#[CoversClass(\App\DTO\Tempo\TempoAccount::class)]
#[CoversClass(\App\DTO\Tempo\TempoAccountLink::class)]
#[CoversClass(\App\DTO\Tempo\TempoCustomerRef::class)]
final class TempoClientTest extends TestCase
{
    use TokenEncryptionTestTrait;

    /**
     * @param array<string, mixed> $tenantResponses absolute path => decoded body
     */
    private function tempoClient(array $tenantResponses): TempoClient
    {
        $user = self::createStub(User::class);
        $ticketSystem = self::createStub(TicketSystem::class);
        $managerRegistry = self::createStub(ManagerRegistry::class);
        $router = self::createStub(RouterInterface::class);
        $tokenEncryptionService = $this->createTokenEncryptionService();

        $api = new class($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService, $tenantResponses) extends JiraOAuthApiService {
            /**
             * @param array<string, mixed> $tenantResponses
             */
            public function __construct(
                User $user,
                TicketSystem $ticketSystem,
                ManagerRegistry $managerRegistry,
                RouterInterface $router,
                TokenEncryptionService $tokenEncryptionService,
                private readonly array $tenantResponses,
            ) {
                parent::__construct($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService);
            }

            public function getFromTenant(string $absolutePath): mixed
            {
                return $this->tenantResponses[$absolutePath] ?? [];
            }
        };

        return new TempoClient($api);
    }

    private static function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    public function testAccountsForProjectParsesCustomerAndCategory(): void
    {
        $client = $this->tempoClient([
            '/rest/tempo-accounts/1/account/project/20350' => self::decode(
                '[{"id":100,"key":"NRIT","name":"NR IT","status":"OPEN",'
                . '"customer":{"id":9,"key":"NR","name":"Netresearch"},'
                . '"category":{"id":9,"key":"OPS","name":"Operations"}}]',
            ),
        ]);

        $accounts = $client->accountsForProject(20350);

        self::assertCount(1, $accounts);
        self::assertSame(100, $accounts[0]->id);
        self::assertSame('NRIT', $accounts[0]->key);
        self::assertSame('Operations', $accounts[0]->categoryName);
        self::assertNotNull($accounts[0]->customer);
        self::assertSame('NR', $accounts[0]->customer->key);
        self::assertSame('Netresearch', $accounts[0]->customer->name);
    }

    public function testAccountsForProjectToleratesAbsentCustomerAndCategory(): void
    {
        $client = $this->tempoClient([
            '/rest/tempo-accounts/1/account/project/17250' => self::decode(
                '[{"id":200,"key":"BARE","name":"Bare Account","status":"OPEN"}]',
            ),
        ]);

        $accounts = $client->accountsForProject(17250);

        self::assertCount(1, $accounts);
        self::assertNull($accounts[0]->customer);
        self::assertNull($accounts[0]->categoryName);
    }

    public function testAccountsForProjectReturnsEmptyWhenNoAccounts(): void
    {
        $client = $this->tempoClient([
            '/rest/tempo-accounts/1/account/project/23050' => self::decode('[]'),
        ]);

        self::assertSame([], $client->accountsForProject(23050));
    }

    public function testAccountsForProjectReturnsEmptyOnNonArray(): void
    {
        $client = $this->tempoClient([
            '/rest/tempo-accounts/1/account/project/999' => (object) ['errorMessages' => ['nope']],
        ]);

        self::assertSame([], $client->accountsForProject(999));
    }

    public function testLinksForProjectParsesDefaultAccountFlag(): void
    {
        $client = $this->tempoClient([
            '/rest/tempo-accounts/1/link/project/20350' => self::decode(
                '[{"id":1017,"scopeType":"PROJECT","scope":20350,"accountId":100,'
                . '"key":"SRVMO","name":"Service Monitoring","linkType":"MANUAL","defaultAccount":true},'
                . '{"id":1018,"scopeType":"PROJECT","scope":20350,"accountId":101,'
                . '"key":"SRVMO","name":"Service Monitoring","linkType":"MANUAL","defaultAccount":false}]',
            ),
        ]);

        $links = $client->linksForProject(20350);

        self::assertCount(2, $links);
        self::assertSame(100, $links[0]->accountId);
        self::assertTrue($links[0]->defaultAccount);
        self::assertSame(101, $links[1]->accountId);
        self::assertFalse($links[1]->defaultAccount);
    }
}

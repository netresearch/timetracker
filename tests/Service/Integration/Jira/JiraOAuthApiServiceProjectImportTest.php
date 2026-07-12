<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiInvalidResourceException;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Security\TokenEncryptionService;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Routing\RouterInterface;
use Tests\Traits\TokenEncryptionTestTrait;

/**
 * Unit tests for the ADR-026 project-import reads on the OAuth1 Jira service:
 * the tenant-absolute GET seam ({@see JiraOAuthApiService::getFromTenant()})
 * and the project id/name/category lookup
 * ({@see JiraOAuthApiService::getProjectInfo()}).
 *
 * @internal
 */
#[CoversClass(JiraOAuthApiService::class)]
final class JiraOAuthApiServiceProjectImportTest extends TestCase
{
    use TokenEncryptionTestTrait;

    /**
     * @param array<string, mixed> $getResponses url => decoded response ('404' simulates a 404)
     */
    private function service(array $getResponses = [], ?Client $tenantClient = null): JiraOAuthApiService
    {
        $user = self::createStub(User::class);
        $ticketSystem = self::createStub(TicketSystem::class);
        $managerRegistry = self::createStub(ManagerRegistry::class);
        $router = self::createStub(RouterInterface::class);
        $tokenEncryptionService = $this->createTokenEncryptionService();

        return new class($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService, $getResponses, $tenantClient) extends JiraOAuthApiService {
            /**
             * @param array<string, mixed> $getResponses
             */
            public function __construct(
                User $user,
                TicketSystem $ticketSystem,
                ManagerRegistry $managerRegistry,
                RouterInterface $router,
                TokenEncryptionService $tokenEncryptionService,
                private readonly array $getResponses,
                private readonly ?Client $tenantClient,
            ) {
                parent::__construct($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService);
            }

            protected function get(string $url): mixed
            {
                $response = $this->getResponses[$url] ?? new stdClass();
                if ('404' === $response) {
                    throw new JiraApiInvalidResourceException('404 - Resource is not available: (' . $url . ')', 404);
                }

                return $response;
            }

            protected function getClient(string $tokenMode = 'user', ?string $oAuthToken = null): Client
            {
                if ($this->tenantClient instanceof Client) {
                    return $this->tenantClient;
                }

                return parent::getClient($tokenMode, $oAuthToken);
            }
        };
    }

    public function testGetFromTenantReturnsDecodedJsonArray(): void
    {
        $captured = [];
        $client = self::createStub(Client::class);
        $client->method('request')->willReturnCallback(
            static function (string $method, string $url) use (&$captured): Response {
                $captured = [$method, $url];

                return new Response(200, [], '[{"id":100,"key":"NRIT","name":"NR IT"}]');
            },
        );

        $decoded = $this->service([], $client)->getFromTenant('/rest/tempo-accounts/1/account/project/20350');

        self::assertSame(['GET', '/rest/tempo-accounts/1/account/project/20350'], $captured);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        $first = $decoded[0];
        self::assertInstanceOf(stdClass::class, $first);
        self::assertSame(100, $first->id);
    }

    public function testGetProjectInfoReturnsIdNameAndCategory(): void
    {
        $service = $this->service([
            'project/SRVMO' => (object) [
                'id' => 20350,
                'key' => 'SRVMO',
                'name' => 'Service Monitoring',
                'projectCategory' => (object) ['id' => 1, 'name' => 'NR: IT Services'],
            ],
        ]);

        $info = $service->getProjectInfo('SRVMO');

        self::assertSame(['id' => 20350, 'name' => 'Service Monitoring', 'categoryName' => 'NR: IT Services'], $info);
    }

    public function testGetProjectInfoCategoryNullWhenAbsent(): void
    {
        $service = $this->service([
            'project/NRFE' => (object) ['id' => 10212, 'key' => 'NRFE', 'name' => 'Frontend'],
        ]);

        $info = $service->getProjectInfo('NRFE');

        self::assertNotNull($info);
        self::assertSame(10212, $info['id']);
        self::assertNull($info['categoryName']);
    }

    public function testGetProjectInfoReturnsNullOn404(): void
    {
        $service = $this->service(['project/NOPE' => '404']);

        self::assertNull($service->getProjectInfo('NOPE'));
    }
}

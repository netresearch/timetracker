<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Security\TokenEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Tests\Traits\TokenEncryptionTestTrait;

use function is_scalar;

/**
 * A 401 from Jira must not replace the stored OAuth access token (#911).
 *
 * The request token fetched to build the authorize URL is not authorized yet;
 * only the OAuth callback, after the user approved it and it was exchanged,
 * may replace the stored access token.
 *
 * @internal
 */
#[CoversClass(JiraOAuthApiService::class)]
#[AllowMockObjectsWithoutExpectations]
final class JiraOAuthApiServiceUnauthorizedTest extends TestCase
{
    use TokenEncryptionTestTrait;

    private TokenEncryptionService $tokenEncryptionService;

    private UserTicketsystem $userTicketSystem;

    private User $user;

    private TicketSystem $ticketSystem;

    /** The users_ticket_systems row the repository finds; null for a user who never connected. */
    private ?UserTicketsystem $storedRow = null;

    protected function setUp(): void
    {
        $this->tokenEncryptionService = $this->createTokenEncryptionService();

        $this->ticketSystem = new TicketSystem();
        $this->ticketSystem->setUrl('https://jira.example.com');
        $this->ticketSystem->setBookTime(true);
        new ReflectionProperty(TicketSystem::class, 'id')->setValue($this->ticketSystem, 7);

        $this->user = new User();

        $this->userTicketSystem = new UserTicketsystem();
        $this->userTicketSystem->setUser($this->user)
            ->setTicketSystem($this->ticketSystem)
            ->setAccessToken($this->tokenEncryptionService->encryptToken('working-access-token'))
            ->setTokenSecret($this->tokenEncryptionService->encryptToken('working-access-secret'));
        $this->user->getUserTicketsystems()->add($this->userTicketSystem);
        $this->storedRow = $this->userTicketSystem;
    }

    public function testA401KeepsTheStoredAccessToken(): void
    {
        $service = $this->createService(
            apiResponses: [new Response(401, [], 'oauth_problem=token_rejected')],
            requestTokenResponses: [new Response(200, [], 'oauth_token=unauthorized-request-token&oauth_token_secret=request-secret')],
        );

        try {
            $service->doesTicketExist('TT-1');
            self::fail('A 401 must surface as JiraApiUnauthorizedException');
        } catch (JiraApiUnauthorizedException $jiraApiUnauthorizedException) {
            self::assertSame('https://tt.example.com/jira/connect/7', $jiraApiUnauthorizedException->getRedirectUrl());
        }

        self::assertSame('working-access-token', $this->tokenEncryptionService->decryptToken($this->userTicketSystem->getAccessToken()));
        self::assertSame('working-access-secret', $this->tokenEncryptionService->decryptToken($this->userTicketSystem->getTokenSecret()));
    }

    /**
     * The 401 notice must not depend on Jira issuing a request token: the link
     * points at the connect route, which issues one bound to the session.
     */
    public function testA401AsksForAuthorizationEvenWhenJiraIssuesNoRequestToken(): void
    {
        $service = $this->createService(
            apiResponses: [new Response(401, [], 'oauth_problem=token_rejected')],
            requestTokenResponses: [new Response(500, [], 'request-token endpoint down')],
        );

        try {
            $service->doesTicketExist('TT-1');
            self::fail('A 401 must surface as JiraApiUnauthorizedException');
        } catch (JiraApiUnauthorizedException $jiraApiUnauthorizedException) {
            self::assertSame('https://tt.example.com/jira/connect/7', $jiraApiUnauthorizedException->getRedirectUrl());
        }
    }

    /**
     * Denying the authorization in Jira (or a forged `oauth_verifier=denied`)
     * must not wipe a working access token or switch the sync off.
     */
    public function testDenyingTheAuthorizationKeepsTheStoredAccessToken(): void
    {
        $service = $this->createService(apiResponses: [], requestTokenResponses: []);

        $service->fetchOAuthAccessToken('request-token', 'denied');

        self::assertSame('working-access-token', $this->tokenEncryptionService->decryptToken($this->userTicketSystem->getAccessToken()));
        self::assertSame('working-access-secret', $this->tokenEncryptionService->decryptToken($this->userTicketSystem->getTokenSecret()));
        self::assertFalse($this->userTicketSystem->getAvoidConnection());
    }

    public function testDenyingWithoutAStoredTokenRecordsTheOptOut(): void
    {
        $this->user = new User();
        $this->storedRow = null;

        $service = $this->createService(apiResponses: [], requestTokenResponses: []);

        $service->fetchOAuthAccessToken('request-token', 'denied');

        $rows = $this->user->getUserTicketsystems();
        self::assertCount(1, $rows);
        $row = $rows->first();
        self::assertInstanceOf(UserTicketsystem::class, $row);
        self::assertTrue($row->getAvoidConnection());
    }

    public function testTheCallbackExchangeReplacesTheStoredAccessToken(): void
    {
        $service = $this->createService(
            apiResponses: [],
            requestTokenResponses: [],
            accessTokenResponses: [new Response(200, [], 'oauth_token=new-access-token&oauth_token_secret=new-access-secret')],
        );

        $service->fetchOAuthAccessToken('authorized-request-token', 'verifier');

        self::assertSame('new-access-token', $this->tokenEncryptionService->decryptToken($this->userTicketSystem->getAccessToken()));
        self::assertSame('new-access-secret', $this->tokenEncryptionService->decryptToken($this->userTicketSystem->getTokenSecret()));
    }

    public function testAFirstConnectionSeesItsTokenInTheSameRequest(): void
    {
        $this->user = new User();
        $this->storedRow = null;

        $service = $this->createService(
            apiResponses: [],
            requestTokenResponses: [],
            accessTokenResponses: [new Response(200, [], 'oauth_token=first-access-token&oauth_token_secret=first-secret')],
        );

        $service->fetchOAuthAccessToken('authorized-request-token', 'verifier');

        $stored = $this->user->getTicketSystemAccessToken($this->ticketSystem);
        self::assertIsString($stored);
        self::assertSame('first-access-token', $this->tokenEncryptionService->decryptToken($stored));
    }

    public function testAnExchangeWithoutATokenKeepsTheStoredAccessToken(): void
    {
        $service = $this->createService(
            apiResponses: [],
            requestTokenResponses: [],
            accessTokenResponses: [new Response(200, [], 'oauth_problem=token_expired')],
        );

        try {
            $service->fetchOAuthAccessToken('authorized-request-token', 'verifier');
            self::fail('An exchange answer without a token must fail');
        } catch (JiraApiException) {
            // expected
        }

        self::assertSame('working-access-token', $this->tokenEncryptionService->decryptToken($this->userTicketSystem->getAccessToken()));
    }

    /**
     * @param list<Response> $apiResponses          answers to requests made with the stored access token
     * @param list<Response> $requestTokenResponses answers to the request-token fetch
     * @param list<Response> $accessTokenResponses  answers to the access-token exchange
     */
    private function createService(array $apiResponses, array $requestTokenResponses, array $accessTokenResponses = []): JiraOAuthApiService
    {
        $repository = self::createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($this->storedRow);

        $managerRegistry = self::createStub(ManagerRegistry::class);
        $managerRegistry->method('getRepository')->willReturn($repository);
        $managerRegistry->method('getManager')->willReturn(self::createStub(EntityManagerInterface::class));

        $router = self::createStub(RouterInterface::class);
        // Absolute only when asked for: the CLI and the API answer carry this URL
        // outside a browser page, where a path alone is not a link.
        $router->method('generate')->willReturnCallback(
            static function (string $name, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string {
                $path = 'jiraConnect' === $name
                    ? '/jira/connect/' . (is_scalar($parameters['ticketSystem'] ?? null) ? (string) $parameters['ticketSystem'] : '')
                    : '/jiraoauthcallback';

                return UrlGeneratorInterface::ABSOLUTE_URL === $referenceType ? 'https://tt.example.com' . $path : $path;
            },
        );

        $clients = [
            'user' => self::client($apiResponses),
            'new' => self::client($requestTokenResponses),
            'request' => self::client($accessTokenResponses),
        ];

        return new class($this->user, $this->ticketSystem, $managerRegistry, $router, $this->tokenEncryptionService, $clients) extends JiraOAuthApiService {
            /**
             * @param array<string, Client> $mockClients
             */
            public function __construct(
                User $user,
                TicketSystem $ticketSystem,
                ManagerRegistry $managerRegistry,
                RouterInterface $router,
                TokenEncryptionService $tokenEncryptionService,
                private readonly array $mockClients,
            ) {
                parent::__construct($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService);
            }

            protected function getClient(string $tokenMode = 'user', ?string $oAuthToken = null): Client
            {
                return $this->mockClients[$tokenMode];
            }
        };
    }

    /**
     * @param list<Response> $responses
     */
    private static function client(array $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }
}

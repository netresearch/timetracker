<?php

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\FrozenClock;
use App\Service\Integration\Jira\CloudOAuthStateCodec;
use App\Service\Integration\Jira\JiraCloudApiService;
use App\Service\Security\TokenEncryptionService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LogicException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Routing\RouterInterface;
use Tests\Traits\TokenEncryptionTestTrait;

use function is_string;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function str_starts_with;

use const PHP_URL_QUERY;

/**
 * Unit tests for the Jira Cloud OAuth 2.0 (3LO) service.
 *
 * @internal
 */
#[CoversClass(JiraCloudApiService::class)]
#[AllowMockObjectsWithoutExpectations]
final class JiraCloudApiServiceTest extends TestCase
{
    use TokenEncryptionTestTrait;

    private const string CALLBACK_URL = 'https://tt.example.com/jiraoauthcallback';

    private User&Stub $user;
    private TicketSystem $ticketSystem;
    private ManagerRegistry&Stub $managerRegistry;
    private RouterInterface&Stub $router;
    private EntityManagerInterface&Stub $entityManager;
    private TokenEncryptionService $tokenEncryptionService;
    private FrozenClock $clock;

    private ?UserTicketsystem $userTicketSystem = null;

    private string $encryptedAccessToken = '';

    /** @var list<object> */
    private array $persisted = [];

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $authRequests = [];

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $restRequests = [];

    /** @var list<array<string, mixed>> */
    private array $clientConfigs = [];

    private string $authResponseBody = '';

    private ?RequestException $authException = null;

    private string $gatewayResponseBody = '[]';

    private string $restResponseBody = '{}';

    protected function setUp(): void
    {
        $this->tokenEncryptionService = $this->createTokenEncryptionService();
        $this->clock = new FrozenClock('2026-07-02 12:00:00');

        $this->user = self::createStub(User::class);
        $this->user->method('getId')->willReturn(42);
        $this->user->method('getTicketSystemAccessToken')->willReturnCallback(fn (): string => $this->encryptedAccessToken);
        $this->user->method('getTicketSystemAccessTokenSecret')->willReturn('');

        $this->ticketSystem = new TicketSystem();
        $idProperty = new ReflectionProperty(TicketSystem::class, 'id');
        $idProperty->setValue($this->ticketSystem, 7);
        $this->ticketSystem->setName('Cloud Jira');
        $this->ticketSystem->setUrl('https://example.atlassian.net');
        $this->ticketSystem->setBookTime(true);
        $this->ticketSystem->setDeploymentType('CLOUD');
        $this->ticketSystem->setOauth2ClientId('client-id-1');
        $this->ticketSystem->setOauth2ClientSecret('client-secret-1');

        $repository = self::createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(fn (): ?UserTicketsystem => $this->userTicketSystem);

        $this->entityManager = self::createStub(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(function (object $object): void {
            $this->persisted[] = $object;
        });

        $this->managerRegistry = self::createStub(ManagerRegistry::class);
        $this->managerRegistry->method('getRepository')->willReturn($repository);
        $this->managerRegistry->method('getManager')->willReturn($this->entityManager);

        $this->router = self::createStub(RouterInterface::class);
        $this->router->method('generate')->willReturn(self::CALLBACK_URL);
    }

    // ==================== authorize redirect ====================

    public function testMissingTokensThrowAuthorizeRedirectWithDecodableState(): void
    {
        $service = $this->createService();

        try {
            $this->invokeGetClient($service);
            self::fail('Expected JiraApiUnauthorizedException');
        } catch (JiraApiUnauthorizedException $exception) {
            $redirectUrl = $exception->getRedirectUrl();
            self::assertIsString($redirectUrl);
            self::assertStringStartsWith('https://auth.atlassian.com/authorize?', $redirectUrl);

            $query = [];
            parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);

            self::assertSame('api.atlassian.com', $query['audience'] ?? null);
            self::assertSame('client-id-1', $query['client_id'] ?? null);
            self::assertSame('read:jira-work write:jira-work offline_access', $query['scope'] ?? null);
            self::assertSame(self::CALLBACK_URL, $query['redirect_uri'] ?? null, 'Cloud redirect_uri must be the bare registered callback (no tsid)');
            self::assertSame('code', $query['response_type'] ?? null);

            $state = $query['state'] ?? null;
            self::assertIsString($state);
            $codec = new CloudOAuthStateCodec($this->tokenEncryptionService, $this->clock);
            self::assertSame(['userId' => 42, 'ticketSystemId' => 7], $codec->decode($state));
        }
    }

    public function testMissingClientIdFailsWithConfigurationError(): void
    {
        $this->ticketSystem->setOauth2ClientId(null);
        $service = $this->createService();

        try {
            $this->invokeGetClient($service);
            self::fail('Expected JiraApiException');
        } catch (JiraApiException $exception) {
            self::assertStringContainsString('no OAuth2 client id', $exception->getMessage());
            self::assertNotInstanceOf(JiraApiUnauthorizedException::class, $exception);
        }
    }

    // ==================== authenticated client ====================

    public function testClientUsesGatewayBaseUrlAndBearerToken(): void
    {
        $this->givenStoredTokens('access-1', 'refresh-1', '+1 hour');
        $this->ticketSystem->setCloudId('cloud-id-9');

        $service = $this->createService();
        $this->invokeGetClient($service);

        $config = $this->lastRestClientConfig();
        self::assertSame('https://api.atlassian.com/ex/jira/cloud-id-9/rest/api/2/', $config['base_uri'] ?? null);
        $headers = $config['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertSame('Bearer access-1', $headers['Authorization'] ?? null);
    }

    public function testExpiredTokenIsRefreshedAndRotatedTokensAreStored(): void
    {
        $this->givenStoredTokens('stale-access', 'refresh-1', '-1 minute');
        $this->ticketSystem->setCloudId('cloud-id-9');
        $this->authResponseBody = (string) json_encode([
            'access_token' => 'fresh-access',
            'refresh_token' => 'fresh-refresh',
            'expires_in' => 3600,
        ]);

        $service = $this->createService();
        $this->invokeGetClient($service);

        // The refresh request carried the rotating grant…
        self::assertCount(1, $this->authRequests);
        $payload = $this->authRequests[0]['options']['json'] ?? null;
        self::assertIsArray($payload);
        self::assertSame('refresh_token', $payload['grant_type'] ?? null);
        self::assertSame('refresh-1', $payload['refresh_token'] ?? null);

        // …and BOTH rotated tokens were stored encrypted, with the new expiry.
        $userTicketSystem = $this->userTicketSystem;
        self::assertInstanceOf(UserTicketsystem::class, $userTicketSystem);
        self::assertSame('fresh-access', $this->tokenEncryptionService->decryptToken($userTicketSystem->getAccessToken()));
        $refreshToken = $userTicketSystem->getRefreshToken();
        self::assertIsString($refreshToken);
        self::assertSame('fresh-refresh', $this->tokenEncryptionService->decryptToken($refreshToken));
        $expiresAt = $userTicketSystem->getTokenExpiresAt();
        self::assertInstanceOf(DateTimeImmutable::class, $expiresAt);
        self::assertSame(
            $this->clock->now()->modify('+3600 seconds')->getTimestamp(),
            $expiresAt->getTimestamp(),
        );

        // The fresh token authenticates the REST client.
        $config = $this->lastRestClientConfig();
        $headers = $config['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertSame('Bearer fresh-access', $headers['Authorization'] ?? null);
    }

    public function testRejectedRefreshClearsTokensAndRestartsAuthorization(): void
    {
        $this->givenStoredTokens('stale-access', 'refresh-1', '-1 minute');
        $this->ticketSystem->setCloudId('cloud-id-9');
        $this->authException = new RequestException(
            'invalid_grant',
            new Request('POST', '/oauth/token'),
            new Response(403),
        );

        $service = $this->createService();

        try {
            $this->invokeGetClient($service);
            self::fail('Expected JiraApiUnauthorizedException');
        } catch (JiraApiUnauthorizedException $exception) {
            self::assertStringContainsString('auth.atlassian.com/authorize', (string) $exception->getRedirectUrl());
        }

        $userTicketSystem = $this->userTicketSystem;
        self::assertInstanceOf(UserTicketsystem::class, $userTicketSystem);
        self::assertSame('', $userTicketSystem->getAccessToken());
        self::assertSame('', $userTicketSystem->getRefreshToken());
    }

    public function testTransientRefreshFailureKeepsTheGrant(): void
    {
        $this->givenStoredTokens('stale-access', 'refresh-1', '-1 minute');
        $this->ticketSystem->setCloudId('cloud-id-9');
        $this->authException = new RequestException(
            'bad gateway',
            new Request('POST', '/oauth/token'),
            new Response(502),
        );

        $service = $this->createService();

        try {
            $this->invokeGetClient($service);
            self::fail('Expected JiraApiException');
        } catch (JiraApiUnauthorizedException $exception) {
            self::fail('A transient failure must not restart authorization');
        } catch (JiraApiException $exception) {
            self::assertSame(502, $exception->getCode());
        }

        // The rotating grant survives for the next sync attempt.
        $userTicketSystem = $this->userTicketSystem;
        self::assertInstanceOf(UserTicketsystem::class, $userTicketSystem);
        $refreshToken = $userTicketSystem->getRefreshToken();
        self::assertIsString($refreshToken);
        self::assertSame('refresh-1', $this->tokenEncryptionService->decryptToken($refreshToken));
    }

    // ==================== authorization-code exchange ====================

    public function testExchangeAuthorizationCodeStoresTokensAndResolvesCloudId(): void
    {
        $this->authResponseBody = (string) json_encode([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
        ]);
        $this->gatewayResponseBody = (string) json_encode([
            ['id' => 'other-cloud', 'url' => 'https://other.atlassian.net'],
            ['id' => 'cloud-id-9', 'url' => 'https://EXAMPLE.atlassian.net'],
        ]);

        $service = $this->createService();
        $service->exchangeAuthorizationCode('auth-code-1');

        // Token exchange used the authorization-code grant with the bare redirect URI.
        $payload = $this->authRequests[0]['options']['json'] ?? null;
        self::assertIsArray($payload);
        self::assertSame('authorization_code', $payload['grant_type'] ?? null);
        self::assertSame('auth-code-1', $payload['code'] ?? null);
        self::assertSame(self::CALLBACK_URL, $payload['redirect_uri'] ?? null);

        // Tokens stored encrypted…
        $userTicketSystem = $this->userTicketSystem ?? $this->findPersistedUserTicketSystem();
        self::assertInstanceOf(UserTicketsystem::class, $userTicketSystem);
        self::assertSame('access-1', $this->tokenEncryptionService->decryptToken($userTicketSystem->getAccessToken()));

        // …and the tenant was matched by host, case-insensitively.
        self::assertSame('cloud-id-9', $this->ticketSystem->getCloudId());
        self::assertContains($this->ticketSystem, $this->persisted);
    }

    public function testExchangeAuthorizationCodeFailsWhenNoSiteMatches(): void
    {
        $this->authResponseBody = (string) json_encode([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
        ]);
        $this->gatewayResponseBody = (string) json_encode([
            ['id' => 'other-cloud', 'url' => 'https://other.atlassian.net'],
        ]);

        $service = $this->createService();

        try {
            $service->exchangeAuthorizationCode('auth-code-1');
            self::fail('Expected JiraApiException');
        } catch (JiraApiException $exception) {
            self::assertStringContainsString('None of the Atlassian sites', $exception->getMessage());
        }
    }

    public function testMalformedTokenResponseIsRejected(): void
    {
        $this->authResponseBody = (string) json_encode(['access_token' => 'only-this']);

        $service = $this->createService();

        try {
            $service->exchangeAuthorizationCode('auth-code-1');
            self::fail('Expected JiraApiException');
        } catch (JiraApiException $exception) {
            self::assertStringContainsString('unexpected response', $exception->getMessage());
        }
    }

    // ==================== REST specifics ====================

    public function testSearchTicketUsesCloudJqlEndpoint(): void
    {
        $this->givenStoredTokens('access-1', 'refresh-1', '+1 hour');
        $this->ticketSystem->setCloudId('cloud-id-9');

        $service = $this->createService();
        $service->searchTicket('project = ABC', ['key'], 5);

        self::assertCount(1, $this->restRequests);
        self::assertSame('POST', $this->restRequests[0]['method']);
        self::assertSame('search/jql', $this->restRequests[0]['url']);
        $body = $this->restRequests[0]['options']['body'] ?? null;
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame('project = ABC', $decoded['jql'] ?? null);
        self::assertSame(5, $decoded['maxResults'] ?? null);
    }

    public function testOAuth1CallbackIsRejected(): void
    {
        $service = $this->createService();

        try {
            $service->fetchOAuthAccessToken('token', 'verifier');
            self::fail('Expected JiraApiException');
        } catch (JiraApiException $exception) {
            self::assertStringContainsString('OAuth 1.0a', $exception->getMessage());
        }
    }

    public function testCallbackUrlCarriesNoTicketSystemParameter(): void
    {
        $service = $this->createService();

        $reflectionMethod = new ReflectionMethod($service, 'getOAuthCallbackUrl');

        self::assertSame(self::CALLBACK_URL, $reflectionMethod->invoke($service));
    }

    // ==================== harness ====================

    private function givenStoredTokens(string $accessToken, string $refreshToken, string $expiresIn): void
    {
        $userTicketSystem = new UserTicketsystem();
        $userTicketSystem->setTicketSystem($this->ticketSystem)
            ->setAccessToken($this->tokenEncryptionService->encryptToken($accessToken))
            ->setTokenSecret('')
            ->setRefreshToken($this->tokenEncryptionService->encryptToken($refreshToken))
            ->setTokenExpiresAt($this->clock->now()->modify($expiresIn));

        $this->userTicketSystem = $userTicketSystem;
        $this->encryptedAccessToken = $this->tokenEncryptionService->encryptToken($accessToken);
    }

    private function findPersistedUserTicketSystem(): ?UserTicketsystem
    {
        foreach ($this->persisted as $object) {
            if ($object instanceof UserTicketsystem) {
                return $object;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRestClientConfig(): array
    {
        foreach (array_reverse($this->clientConfigs) as $config) {
            $base = $config['base_uri'] ?? '';
            if (is_string($base) && str_starts_with($base, 'https://api.atlassian.com/ex/')) {
                return $config;
            }
        }

        self::fail('No REST client was created');
    }

    private function invokeGetClient(JiraCloudApiService $service): Client
    {
        $reflectionMethod = new ReflectionMethod($service, 'getClient');
        $client = $reflectionMethod->invoke($service);
        self::assertInstanceOf(Client::class, $client);

        return $client;
    }

    private function createService(): JiraCloudApiService
    {
        $test = $this;

        return new class($this->user, $this->ticketSystem, $this->managerRegistry, $this->router, $this->tokenEncryptionService, $this->clock, $test) extends JiraCloudApiService {
            public function __construct(
                User $user,
                TicketSystem $ticketSystem,
                ManagerRegistry $managerRegistry,
                RouterInterface $router,
                TokenEncryptionService $tokenEncryptionService,
                FrozenClock $clock,
                private readonly JiraCloudApiServiceTest $test,
            ) {
                parent::__construct($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService, $clock);
            }

            protected function createHttpClient(array $config): Client
            {
                return $this->test->routeClient($config);
            }
        };
    }

    /**
     * Test seam target: records every client the service creates and returns
     * a per-endpoint stub (auth.atlassian.com token endpoint, bare gateway
     * for accessible-resources, tenant REST base).
     *
     * @param array<string, mixed> $config
     */
    public function routeClient(array $config): Client
    {
        $this->clientConfigs[] = $config;
        $base = $config['base_uri'] ?? '';
        self::assertIsString($base);

        if (str_starts_with($base, 'https://auth.atlassian.com')) {
            return $this->stubClient($this->authRequests, fn (): string => $this->authResponseBody, $this->authException);
        }

        if ('https://api.atlassian.com' === $base) {
            return $this->stubClient($this->restRequests, fn (): string => $this->gatewayResponseBody, null);
        }

        if (str_starts_with($base, 'https://api.atlassian.com/ex/')) {
            return $this->stubClient($this->restRequests, fn (): string => $this->restResponseBody, null);
        }

        throw new LogicException('Unrouted client base_uri: ' . $base);
    }

    /**
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $log
     * @param callable(): string                                                      $body
     */
    private function stubClient(array &$log, callable $body, ?RequestException $throw): Client
    {
        $client = self::createStub(Client::class);
        $client->method('request')->willReturnCallback(
            static function (string $method, string $url, array $options = []) use (&$log, $body, $throw): Response {
                $log[] = ['method' => $method, 'url' => $url, 'options' => $options];
                if ($throw instanceof RequestException) {
                    throw $throw;
                }

                return new Response(200, ['Content-Type' => 'application/json'], $body());
            },
        );

        return $client;
    }
}

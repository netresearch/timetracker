<?php

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Exception\Integration\Jira\JiraApiException;
use App\Service\Integration\Jira\JiraOAuthApiService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

use function assert;
use function is_string;
use function json_encode;

/**
 * Unit tests for JiraOAuthApiService.
 *
 * @internal
 */
#[CoversClass(JiraOAuthApiService::class)]
final class JiraOAuthApiServiceTest extends TestCase
{
    private User&MockObject $user;
    private TicketSystem&MockObject $ticketSystem;
    private ManagerRegistry&MockObject $managerRegistry;
    private RouterInterface&MockObject $router;
    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->user = $this->createMock(User::class);
        $this->ticketSystem = $this->createMock(TicketSystem::class);
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
        $this->router = $this->createMock(RouterInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->ticketSystem->method('getUrl')->willReturn('https://jira.example.com');
        $this->ticketSystem->method('getId')->willReturn(1);
        $this->ticketSystem->method('getName')->willReturn('Test Jira');

        $this->router->method('generate')
            ->with('jiraOAuthCallback', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://app.example.com/jira/callback');
    }

    // ==================== URL building tests ====================

    public function testGetJiraBaseUrlTrimsTrailingSlash(): void
    {
        $this->ticketSystem = $this->createMock(TicketSystem::class);
        $this->ticketSystem->method('getUrl')->willReturn('https://jira.example.com/');
        $this->ticketSystem->method('getId')->willReturn(1);

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getJiraBaseUrl');

        self::assertSame('https://jira.example.com', $method->invoke($service));
    }

    public function testGetJiraApiUrlConcatenatesCorrectly(): void
    {
        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getJiraApiUrl');

        self::assertSame('https://jira.example.com/rest/api/latest/', $method->invoke($service));
    }

    public function testGetOAuthRequestUrlBuildsCorrectly(): void
    {
        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getOAuthRequestUrl');

        self::assertSame('https://jira.example.com/plugins/servlet/oauth/request-token', $method->invoke($service));
    }

    public function testGetOAuthAccessUrlBuildsCorrectly(): void
    {
        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getOAuthAccessUrl');

        self::assertSame('https://jira.example.com/plugins/servlet/oauth/access-token', $method->invoke($service));
    }

    public function testGetOAuthCallbackUrlIncludesTicketSystemId(): void
    {
        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getOAuthCallbackUrl');

        self::assertSame('https://app.example.com/jira/callback?tsid=1', $method->invoke($service));
    }

    public function testGetOAuthAuthUrlIncludesToken(): void
    {
        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getOAuthAuthUrl');

        $result = $method->invoke($service, 'test_token_123');

        self::assertSame('https://jira.example.com/plugins/servlet/oauth/authorize?oauth_token=test_token_123', $result);
    }

    // ==================== Token handling tests ====================

    public function testGetTokenSecretReturnsEmptyStringWhenNull(): void
    {
        $this->user->method('getTicketSystemAccessTokenSecret')->willReturn(null);

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getTokenSecret');

        self::assertSame('', $method->invoke($service));
    }

    public function testGetTokenSecretReturnsValue(): void
    {
        $this->user->method('getTicketSystemAccessTokenSecret')->willReturn('my_secret');

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getTokenSecret');

        self::assertSame('my_secret', $method->invoke($service));
    }

    public function testGetTokenReturnsEmptyStringWhenNull(): void
    {
        $this->user->method('getTicketSystemAccessToken')->willReturn(null);

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getToken');

        self::assertSame('', $method->invoke($service));
    }

    public function testGetTokenReturnsValue(): void
    {
        $this->user->method('getTicketSystemAccessToken')->willReturn('my_token');

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getToken');

        self::assertSame('my_token', $method->invoke($service));
    }

    // ==================== OAuth consumer tests ====================

    public function testGetOAuthConsumerKeyReturnsEmptyWhenNull(): void
    {
        $this->ticketSystem->method('getOauthConsumerKey')->willReturn(null);

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getOAuthConsumerKey');

        self::assertSame('', $method->invoke($service));
    }

    public function testGetOAuthConsumerKeyReturnsValue(): void
    {
        $this->ticketSystem->method('getOauthConsumerKey')->willReturn('consumer_key');

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getOAuthConsumerKey');

        self::assertSame('consumer_key', $method->invoke($service));
    }

    public function testGetOAuthConsumerSecretReturnsEmptyWhenNull(): void
    {
        $this->ticketSystem->method('getOauthConsumerSecret')->willReturn(null);

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getOAuthConsumerSecret');

        self::assertSame('', $method->invoke($service));
    }

    // ==================== Worklog comment formatting tests ====================

    public function testGetTicketSystemWorkLogCommentWithActivity(): void
    {
        $activity = $this->createMock(Activity::class);
        $activity->method('getName')->willReturn('Development');

        $entry = $this->createMock(Entry::class);
        $entry->method('getId')->willReturn(123);
        $entry->method('getActivity')->willReturn($activity);
        $entry->method('getDescription')->willReturn('Fixed bug in login');

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getTicketSystemWorkLogComment');

        $result = $method->invoke($service, $entry);

        self::assertSame('#123: Development: Fixed bug in login', $result);
    }

    public function testGetTicketSystemWorkLogCommentWithoutActivity(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getId')->willReturn(456);
        $entry->method('getActivity')->willReturn(null);
        $entry->method('getDescription')->willReturn('Some work done');

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getTicketSystemWorkLogComment');

        $result = $method->invoke($service, $entry);

        self::assertSame('#456: no activity specified: Some work done', $result);
    }

    public function testGetTicketSystemWorkLogCommentWithEmptyDescription(): void
    {
        $activity = $this->createMock(Activity::class);
        $activity->method('getName')->willReturn('Meeting');

        $entry = $this->createMock(Entry::class);
        $entry->method('getId')->willReturn(789);
        $entry->method('getActivity')->willReturn($activity);
        $entry->method('getDescription')->willReturn('');

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getTicketSystemWorkLogComment');

        $result = $method->invoke($service, $entry);

        self::assertSame('#789: Meeting: no description given', $result);
    }

    public function testGetTicketSystemWorkLogCommentWithZeroDescription(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getId')->willReturn(1);
        $entry->method('getActivity')->willReturn(null);
        $entry->method('getDescription')->willReturn('0');

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getTicketSystemWorkLogComment');

        $result = $method->invoke($service, $entry);

        self::assertSame('#1: no activity specified: no description given', $result);
    }

    // ==================== Worklog date formatting tests ====================

    public function testGetTicketSystemWorkLogStartDateFormatsCorrectly(): void
    {
        $day = new DateTime('2025-01-15');
        $start = new DateTime('2025-01-15 09:30:00');

        $entry = $this->createMock(Entry::class);
        $entry->method('getDay')->willReturn($day);
        $entry->method('getStart')->willReturn($start);

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getTicketSystemWorkLogStartDate');

        $result = $method->invoke($service, $entry);
        assert(is_string($result));

        // Should be in format: 2025-01-15T09:30:00.000+0100 (timezone varies)
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T09:30:00\.000[+-]\d{4}$/', $result);
    }

    // ==================== User ticket system check tests ====================

    public function testCheckUserTicketSystemReturnsFalseWhenBookTimeDisabled(): void
    {
        $this->ticketSystem->method('getBookTime')->willReturn(false);

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('checkUserTicketSystem');

        self::assertFalse($method->invoke($service));
    }

    public function testCheckUserTicketSystemReturnsTrueWhenNoUserTicketSystemExists(): void
    {
        $this->ticketSystem->method('getBookTime')->willReturn(true);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $this->managerRegistry->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($repository);

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('checkUserTicketSystem');

        self::assertTrue($method->invoke($service));
    }

    public function testCheckUserTicketSystemReturnsFalseWhenAvoidConnectionSet(): void
    {
        $this->ticketSystem->method('getBookTime')->willReturn(true);

        $userTicketSystem = $this->createMock(UserTicketsystem::class);
        $userTicketSystem->method('getAvoidConnection')->willReturn(true);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($userTicketSystem);

        $this->managerRegistry->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($repository);

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('checkUserTicketSystem');

        self::assertFalse($method->invoke($service));
    }

    public function testCheckUserTicketSystemReturnsTrueWhenAvoidConnectionNotSet(): void
    {
        $this->ticketSystem->method('getBookTime')->willReturn(true);

        $userTicketSystem = $this->createMock(UserTicketsystem::class);
        $userTicketSystem->method('getAvoidConnection')->willReturn(false);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($userTicketSystem);

        $this->managerRegistry->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($repository);

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('checkUserTicketSystem');

        self::assertTrue($method->invoke($service));
    }

    // ==================== Entry worklog methods with early returns ====================

    public function testUpdateEntryJiraWorkLogReturnsEarlyForEmptyTicket(): void
    {
        $this->expectNotToPerformAssertions();

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('');

        $service = $this->createServiceWithMockedClient();
        $service->updateEntryJiraWorkLog($entry);
    }

    public function testUpdateEntryJiraWorkLogReturnsEarlyForZeroTicket(): void
    {
        $this->expectNotToPerformAssertions();

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('0');

        $service = $this->createServiceWithMockedClient();
        $service->updateEntryJiraWorkLog($entry);
    }

    public function testDeleteEntryJiraWorkLogReturnsEarlyForEmptyTicket(): void
    {
        $this->expectNotToPerformAssertions();

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('');

        $service = $this->createServiceWithMockedClient();
        $service->deleteEntryJiraWorkLog($entry);
    }

    public function testDeleteEntryJiraWorkLogReturnsEarlyForNullWorklogId(): void
    {
        $this->expectNotToPerformAssertions();

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('TEST-123');
        $entry->method('getWorklogId')->willReturn(null);

        $service = $this->createServiceWithMockedClient();
        $service->deleteEntryJiraWorkLog($entry);
    }

    public function testDeleteEntryJiraWorkLogReturnsEarlyForZeroWorklogId(): void
    {
        $this->expectNotToPerformAssertions();

        $entry = $this->createMock(Entry::class);
        $entry->method('getTicket')->willReturn('TEST-123');
        $entry->method('getWorklogId')->willReturn(0);

        $service = $this->createServiceWithMockedClient();
        $service->deleteEntryJiraWorkLog($entry);
    }

    // ==================== Create ticket tests ====================

    public function testCreateTicketThrowsExceptionForEntryWithoutProject(): void
    {
        $entry = $this->createMock(Entry::class);
        $entry->method('getProject')->willReturn(null);

        $service = $this->createServiceWithMockedClient();

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Entry has no project');

        $service->createTicket($entry);
    }

    // ==================== Private key file tests ====================

    public function testGetPrivateKeyFileReturnsPathForExistingFile(): void
    {
        // Create temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_key');
        assert(false !== $tempFile);
        file_put_contents($tempFile, 'test key content');

        try {
            $this->ticketSystem->method('getOauthConsumerSecret')->willReturn($tempFile);

            $service = $this->createService();

            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('getPrivateKeyFile');

            self::assertSame($tempFile, $method->invoke($service));
        } finally {
            unlink($tempFile);
        }
    }

    public function testGetPrivateKeyFileCreatesTempFileForPemContent(): void
    {
        $pemContent = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBg...test...";

        $this->ticketSystem->method('getOauthConsumerSecret')->willReturn($pemContent);

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getPrivateKeyFile');

        $result = $method->invoke($service);
        assert(is_string($result));

        self::assertFileExists($result);
        self::assertSame($pemContent, file_get_contents($result));

        // Cleanup
        unlink($result);
    }

    public function testGetPrivateKeyFileThrowsForInvalidCertificate(): void
    {
        $this->ticketSystem->method('getOauthConsumerSecret')->willReturn('invalid_cert_data');

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getPrivateKeyFile');

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Invalid certificate');

        $method->invoke($service);
    }

    // ==================== Extract tokens tests ====================

    public function testExtractTokensThrowsForEmptyResponse(): void
    {
        $response = new Response(200, [], '');

        $this->setupStoreTokenMock();

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('extractTokens');

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('An unknown error occurred while requesting OAuth token');

        $method->invoke($service, $response);
    }

    public function testExtractTokensParsesTokensCorrectly(): void
    {
        $response = new Response(200, [], 'oauth_token=access123&oauth_token_secret=secret456');

        $this->setupStoreTokenMock();

        $service = $this->createService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('extractTokens');

        $result = $method->invoke($service, $response);

        self::assertIsArray($result);
        self::assertArrayHasKey('oauth_token', $result);
        self::assertArrayHasKey('oauth_token_secret', $result);
    }

    // ==================== doesResourceExist tests ====================

    public function testDoesResourceExistReturnsTrueForValidResource(): void
    {
        $service = $this->createServiceWithMockedClientReturning(
            new Response(200, [], (string) json_encode((object) ['id' => '123'])),
        );

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('doesResourceExist');

        self::assertTrue($method->invoke($service, 'issue/TEST-1'));
    }

    public function testDoesResourceExistReturnsFalseFor404(): void
    {
        $request = new Request('GET', 'https://jira.example.com');
        $exception = new RequestException('Not found', $request, new Response(404));

        $service = $this->createServiceWithMockedClientThrowing($exception);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('doesResourceExist');

        self::assertFalse($method->invoke($service, 'issue/NONEXISTENT'));
    }

    // ==================== doesTicketExist tests ====================

    public function testDoesTicketExistCallsCorrectEndpoint(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('request')
            ->with('GET', 'issue/TEST-123', [])
            ->willReturn(new Response(200, [], (string) json_encode((object) ['key' => 'TEST-123'])));

        $service = $this->createServiceWithClient($client);

        self::assertTrue($service->doesTicketExist('TEST-123'));
    }

    // ==================== getSubtickets tests ====================

    public function testGetSubticketsReturnsEmptyForNonExistentTicket(): void
    {
        $request = new Request('GET', 'https://jira.example.com');
        $exception = new RequestException('Not found', $request, new Response(404));

        $service = $this->createServiceWithMockedClientThrowing($exception);

        $result = $service->getSubtickets('NONEXISTENT-123');

        self::assertSame([], $result);
    }

    // ==================== fetchOAuthAccessToken tests ====================

    public function testFetchOAuthAccessTokenDeletesTokensWhenDenied(): void
    {
        $userTicketSystem = $this->createMock(UserTicketsystem::class);
        $userTicketSystem->method('getTokenSecret')->willReturn('');
        $userTicketSystem->method('getAccessToken')->willReturn('');
        $userTicketSystem->method('setUser')->willReturnSelf();
        $userTicketSystem->method('setTicketSystem')->willReturnSelf();
        $userTicketSystem->method('setTokenSecret')->willReturnSelf();
        $userTicketSystem->method('setAccessToken')->willReturnSelf();
        $userTicketSystem->expects(self::once())
            ->method('setAvoidConnection')
            ->with(true)
            ->willReturnSelf();

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($userTicketSystem);

        $this->managerRegistry->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($repository);

        $this->managerRegistry->method('getManager')
            ->willReturn($this->entityManager);

        $service = $this->createService();

        $service->fetchOAuthAccessToken('request_token', 'denied');
    }

    // ==================== Helper methods ====================

    private function createService(): JiraOAuthApiService
    {
        return new JiraOAuthApiService(
            $this->user,
            $this->ticketSystem,
            $this->managerRegistry,
            $this->router,
        );
    }

    private function createServiceWithMockedClient(): JiraOAuthApiService
    {
        $this->ticketSystem->method('getBookTime')->willReturn(false);

        return $this->createService();
    }

    private function createServiceWithMockedClientReturning(ResponseInterface $response): JiraOAuthApiService
    {
        return $this->createServiceWithClient(
            $this->createClientReturning($response),
        );
    }

    private function createServiceWithMockedClientThrowing(Throwable $exception): JiraOAuthApiService
    {
        return $this->createServiceWithClient(
            $this->createClientThrowing($exception),
        );
    }

    private function createServiceWithClient(Client $client): JiraOAuthApiService
    {
        $this->user->method('getTicketSystemAccessToken')->willReturn('token');
        $this->user->method('getTicketSystemAccessTokenSecret')->willReturn('secret');
        $this->ticketSystem->method('getOauthConsumerKey')->willReturn('consumer_key');
        $this->ticketSystem->method('getOauthConsumerSecret')->willReturn("-----BEGIN PRIVATE KEY-----\ntest");

        $user = $this->user;
        $ticketSystem = $this->ticketSystem;
        $managerRegistry = $this->managerRegistry;
        $router = $this->router;

        return new class($user, $ticketSystem, $managerRegistry, $router, $client) extends JiraOAuthApiService {
            public function __construct(
                User $user,
                TicketSystem $ticketSystem,
                ManagerRegistry $managerRegistry,
                RouterInterface $router,
                private Client $mockClient,
            ) {
                parent::__construct($user, $ticketSystem, $managerRegistry, $router);
            }

            protected function getClient(string $tokenMode = 'user', ?string $oAuthToken = null): Client
            {
                return $this->mockClient;
            }
        };
    }

    private function createClientReturning(ResponseInterface $response): Client
    {
        $client = $this->createMock(Client::class);
        $client->method('request')->willReturn($response);

        return $client;
    }

    private function createClientThrowing(Throwable $exception): Client
    {
        $client = $this->createMock(Client::class);
        $client->method('request')->willThrowException($exception);

        return $client;
    }

    private function setupStoreTokenMock(): void
    {
        $userTicketSystem = $this->createMock(UserTicketsystem::class);
        $userTicketSystem->method('getTokenSecret')->willReturn('secret');
        $userTicketSystem->method('getAccessToken')->willReturn('token');
        $userTicketSystem->method('setUser')->willReturnSelf();
        $userTicketSystem->method('setTicketSystem')->willReturnSelf();
        $userTicketSystem->method('setTokenSecret')->willReturnSelf();
        $userTicketSystem->method('setAccessToken')->willReturnSelf();
        $userTicketSystem->method('setAvoidConnection')->willReturnSelf();

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($userTicketSystem);

        $this->managerRegistry->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($repository);

        $this->managerRegistry->method('getManager')
            ->willReturn($this->entityManager);
    }
}

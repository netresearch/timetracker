<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\Integration\Jira\JiraAuthenticationService;
use App\Service\Integration\Jira\JiraHttpClientService;
use App\Service\Security\TokenEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(JiraAuthenticationService::class)]
final class JiraAuthenticationServiceTest extends TestCase
{
    private ManagerRegistry&MockObject $managerRegistry;
    private RouterInterface&MockObject $router;
    private TokenEncryptionService&MockObject $tokenEncryptionService;
    private EntityManagerInterface&MockObject $entityManager;
    private EntityRepository&MockObject $repository;
    private JiraAuthenticationService $service;

    protected function setUp(): void
    {
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
        $this->router = $this->createMock(RouterInterface::class);
        $this->tokenEncryptionService = $this->createMock(TokenEncryptionService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->router->method('generate')
            ->with('jiraOAuthCallback', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://app.example.com/jira/oauth/callback');

        $this->service = new JiraAuthenticationService(
            $this->managerRegistry,
            $this->router,
            $this->tokenEncryptionService,
        );
    }

    private function createUser(int $id = 1): User
    {
        $user = new User();
        $user->setUsername('testuser');
        $user->setType('DEV');
        $user->setLocale('en');
        $user->setAbbr('TU');

        // Use reflection to set ID
        $reflection = new ReflectionClass($user);
        $property = $reflection->getProperty('id');
        $property->setValue($user, $id);

        return $user;
    }

    private function createTicketSystem(int $id = 1, string $url = 'https://jira.example.com'): TicketSystem
    {
        $ticketSystem = new TicketSystem();
        $ticketSystem->setName('Test Jira');
        $ticketSystem->setUrl($url);
        $ticketSystem->setLogin('consumer_key');
        $ticketSystem->setPrivateKey('private_key_content');

        $reflection = new ReflectionClass($ticketSystem);
        $property = $reflection->getProperty('id');
        $property->setValue($ticketSystem, $id);

        return $ticketSystem;
    }

    #[Test]
    public function getOAuthCallbackUrlReturnsGeneratedUrl(): void
    {
        $this->assertSame('https://app.example.com/jira/oauth/callback', $this->service->getOAuthCallbackUrl());
    }

    #[Test]
    public function getOAuthAuthUrlConstructsCorrectUrl(): void
    {
        $ticketSystem = $this->createTicketSystem();

        $url = $this->service->getOAuthAuthUrl($ticketSystem, 'test_token');

        $this->assertSame(
            'https://jira.example.com/plugins/servlet/oauth/authorize?oauth_token=test_token',
            $url,
        );
    }

    #[Test]
    public function throwUnauthorizedRedirectThrowsException(): void
    {
        $ticketSystem = $this->createTicketSystem();

        $this->expectException(JiraApiUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorized. Redirecting to Jira OAuth.');

        $this->service->throwUnauthorizedRedirect($ticketSystem);
    }

    #[Test]
    public function throwUnauthorizedRedirectIncludesOriginalException(): void
    {
        $ticketSystem = $this->createTicketSystem();
        $originalException = new Exception('Original error');

        try {
            $this->service->throwUnauthorizedRedirect($ticketSystem, $originalException);
            $this->fail('Expected JiraApiUnauthorizedException');
        } catch (JiraApiUnauthorizedException $e) {
            $this->assertSame($originalException, $e->getPrevious());
        }
    }

    #[Test]
    public function getTokensReturnsEmptyWhenNoUserTicketSystem(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $this->managerRegistry->method('getManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn(null);

        $tokens = $this->service->getTokens($user, $ticketSystem);

        $this->assertSame(['token' => '', 'secret' => ''], $tokens);
    }

    #[Test]
    public function getTokensDecryptsStoredTokens(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $userTicketSystem = new UserTicketsystem();
        $userTicketSystem->setUser($user);
        $userTicketSystem->setTicketSystem($ticketSystem);
        $userTicketSystem->setAccessToken('encrypted_token');
        $userTicketSystem->setTokenSecret('encrypted_secret');

        $this->managerRegistry->method('getManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn($userTicketSystem);

        $this->tokenEncryptionService->expects($this->exactly(2))
            ->method('decryptToken')
            ->willReturnMap([
                ['encrypted_token', 'decrypted_token'],
                ['encrypted_secret', 'decrypted_secret'],
            ]);

        $tokens = $this->service->getTokens($user, $ticketSystem);

        $this->assertSame('decrypted_token', $tokens['token']);
        $this->assertSame('decrypted_secret', $tokens['secret']);
    }

    #[Test]
    public function getTokensHandlesLegacyUnencryptedTokens(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $userTicketSystem = new UserTicketsystem();
        $userTicketSystem->setUser($user);
        $userTicketSystem->setTicketSystem($ticketSystem);
        $userTicketSystem->setAccessToken('legacy_token');
        $userTicketSystem->setTokenSecret('legacy_secret');

        $this->managerRegistry->method('getManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn($userTicketSystem);

        // Decryption fails for legacy tokens
        $this->tokenEncryptionService->method('decryptToken')
            ->willThrowException(new Exception('Decryption failed'));

        $tokens = $this->service->getTokens($user, $ticketSystem);

        // Falls back to raw values
        $this->assertSame('legacy_token', $tokens['token']);
        $this->assertSame('legacy_secret', $tokens['secret']);
    }

    #[Test]
    public function deleteTokensRemovesUserTicketSystem(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $userTicketSystem = new UserTicketsystem();
        $userTicketSystem->setUser($user);
        $userTicketSystem->setTicketSystem($ticketSystem);

        $this->managerRegistry->method('getManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn($userTicketSystem);

        $this->entityManager->expects($this->once())
            ->method('remove')
            ->with($userTicketSystem);
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->deleteTokens($user, $ticketSystem);
    }

    #[Test]
    public function deleteTokensDoesNothingWhenNoUserTicketSystem(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $this->managerRegistry->method('getManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn(null);

        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');

        $this->service->deleteTokens($user, $ticketSystem);
    }

    #[Test]
    public function checkUserTicketSystemReturnsTrueWhenValid(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $userTicketSystem = new UserTicketsystem();
        $userTicketSystem->setUser($user);
        $userTicketSystem->setTicketSystem($ticketSystem);
        $userTicketSystem->setAvoidConnection(false);

        $this->managerRegistry->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn($userTicketSystem);

        $result = $this->service->checkUserTicketSystem($user, $ticketSystem);

        $this->assertTrue($result);
    }

    #[Test]
    public function checkUserTicketSystemReturnsFalseWhenAvoidConnection(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $userTicketSystem = new UserTicketsystem();
        $userTicketSystem->setUser($user);
        $userTicketSystem->setTicketSystem($ticketSystem);
        $userTicketSystem->setAvoidConnection(true);

        $this->managerRegistry->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn($userTicketSystem);

        $result = $this->service->checkUserTicketSystem($user, $ticketSystem);

        $this->assertFalse($result);
    }

    #[Test]
    public function checkUserTicketSystemReturnsFalseWhenNoRecord(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $this->managerRegistry->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn(null);

        $result = $this->service->checkUserTicketSystem($user, $ticketSystem);

        $this->assertFalse($result);
    }

    #[Test]
    public function authenticateSucceedsWithValidTokens(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $userTicketSystem = new UserTicketsystem();
        $userTicketSystem->setUser($user);
        $userTicketSystem->setTicketSystem($ticketSystem);
        $userTicketSystem->setAvoidConnection(false);
        $userTicketSystem->setAccessToken('encrypted_token');
        $userTicketSystem->setTokenSecret('encrypted_secret');

        $this->managerRegistry->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->managerRegistry->method('getManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn($userTicketSystem);

        $this->tokenEncryptionService->method('decryptToken')
            ->willReturnMap([
                ['encrypted_token', 'valid_token'],
                ['encrypted_secret', 'valid_secret'],
            ]);

        // Should not throw
        $this->service->authenticate($user, $ticketSystem);
        $this->assertTrue(true); // Reached without exception
    }

    #[Test]
    public function authenticateThrowsWhenNoUserTicketSystem(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $this->managerRegistry->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn(null);

        $this->expectException(JiraApiUnauthorizedException::class);

        $this->service->authenticate($user, $ticketSystem);
    }

    #[Test]
    public function authenticateThrowsWhenEmptyToken(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $userTicketSystem = new UserTicketsystem();
        $userTicketSystem->setUser($user);
        $userTicketSystem->setTicketSystem($ticketSystem);
        $userTicketSystem->setAvoidConnection(false);
        $userTicketSystem->setAccessToken('');
        $userTicketSystem->setTokenSecret('');

        $this->managerRegistry->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->managerRegistry->method('getManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn($userTicketSystem);

        $this->tokenEncryptionService->method('decryptToken')->willReturn('');

        $this->expectException(JiraApiUnauthorizedException::class);

        $this->service->authenticate($user, $ticketSystem);
    }

    #[Test]
    public function fetchOAuthRequestTokenSucceeds(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $client = $this->createMock(Client::class);
        $response = new Response(200, [], 'oauth_token=request_token&oauth_token_secret=temp_secret');

        $client->method('post')
            ->with(
                'https://jira.example.com/plugins/servlet/oauth/request-token',
                ['auth' => 'oauth'],
            )
            ->willReturn($response);

        $httpClientService = $this->createMock(JiraHttpClientService::class);
        $httpClientService->method('getClient')
            ->with('new')
            ->willReturn($client);
        $httpClientService->method('getTicketSystem')->willReturn($ticketSystem);
        $httpClientService->method('getUser')->willReturn($user);

        $this->managerRegistry->method('getManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn(null);

        $this->tokenEncryptionService->method('encryptToken')
            ->willReturnCallback(static fn ($token) => 'encrypted_' . $token);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $requestToken = $this->service->fetchOAuthRequestToken($httpClientService);

        $this->assertSame('request_token', $requestToken);
    }

    #[Test]
    public function fetchOAuthRequestTokenThrowsOnEmptyResponse(): void
    {
        $ticketSystem = $this->createTicketSystem();

        $client = $this->createMock(Client::class);
        $response = new Response(200, [], '');

        $client->method('post')->willReturn($response);

        $httpClientService = $this->createMock(JiraHttpClientService::class);
        $httpClientService->method('getClient')->willReturn($client);
        $httpClientService->method('getTicketSystem')->willReturn($ticketSystem);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Empty response from Jira OAuth endpoint');

        $this->service->fetchOAuthRequestToken($httpClientService);
    }

    #[Test]
    public function fetchOAuthRequestTokenThrowsOnOAuthProblem(): void
    {
        $ticketSystem = $this->createTicketSystem();

        $client = $this->createMock(Client::class);
        $response = new Response(200, [], 'oauth_problem=token_rejected');

        $client->method('post')->willReturn($response);

        $httpClientService = $this->createMock(JiraHttpClientService::class);
        $httpClientService->method('getClient')->willReturn($client);
        $httpClientService->method('getTicketSystem')->willReturn($ticketSystem);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('OAuth problem: token_rejected');

        $this->service->fetchOAuthRequestToken($httpClientService);
    }

    #[Test]
    public function fetchOAuthRequestTokenThrowsOnMissingToken(): void
    {
        $ticketSystem = $this->createTicketSystem();

        $client = $this->createMock(Client::class);
        $response = new Response(200, [], 'some_other_param=value');

        $client->method('post')->willReturn($response);

        $httpClientService = $this->createMock(JiraHttpClientService::class);
        $httpClientService->method('getClient')->willReturn($client);
        $httpClientService->method('getTicketSystem')->willReturn($ticketSystem);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Could not fetch OAuth request token');

        $this->service->fetchOAuthRequestToken($httpClientService);
    }

    #[Test]
    public function fetchOAuthAccessTokenSucceeds(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $client = $this->createMock(Client::class);
        $response = new Response(200, [], 'oauth_token=access_token&oauth_token_secret=access_secret');

        $client->method('post')
            ->with(
                'https://jira.example.com/plugins/servlet/oauth/access-token',
                [
                    'auth' => 'oauth',
                    'form_params' => ['oauth_verifier' => 'verifier_code'],
                ],
            )
            ->willReturn($response);

        $httpClientService = $this->createMock(JiraHttpClientService::class);
        $httpClientService->method('getClient')
            ->with('request', 'request_token')
            ->willReturn($client);
        $httpClientService->method('getTicketSystem')->willReturn($ticketSystem);
        $httpClientService->method('getUser')->willReturn($user);

        $this->managerRegistry->method('getManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn(null);

        $this->tokenEncryptionService->method('encryptToken')
            ->willReturnCallback(static fn ($token) => 'encrypted_' . $token);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        // Should not throw
        $this->service->fetchOAuthAccessToken($httpClientService, 'request_token', 'verifier_code');
        $this->assertTrue(true);
    }

    #[Test]
    public function fetchOAuthAccessTokenThrowsOnMissingTokenSecret(): void
    {
        $ticketSystem = $this->createTicketSystem();

        $client = $this->createMock(Client::class);
        $response = new Response(200, [], 'oauth_token=access_token');

        $client->method('post')->willReturn($response);

        $httpClientService = $this->createMock(JiraHttpClientService::class);
        $httpClientService->method('getClient')->willReturn($client);
        $httpClientService->method('getTicketSystem')->willReturn($ticketSystem);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Could not fetch OAuth access token');

        $this->service->fetchOAuthAccessToken($httpClientService, 'request_token', 'verifier_code');
    }

    #[Test]
    public function storeTokenUpdatesExistingUserTicketSystem(): void
    {
        $user = $this->createUser();
        $ticketSystem = $this->createTicketSystem();

        $existingUserTicketSystem = new UserTicketsystem();
        $existingUserTicketSystem->setUser($user);
        $existingUserTicketSystem->setTicketSystem($ticketSystem);
        $existingUserTicketSystem->setAccessToken('old_token');

        $client = $this->createMock(Client::class);
        $response = new Response(200, [], 'oauth_token=new_token&oauth_token_secret=new_secret');

        $client->method('post')->willReturn($response);

        $httpClientService = $this->createMock(JiraHttpClientService::class);
        $httpClientService->method('getClient')->willReturn($client);
        $httpClientService->method('getTicketSystem')->willReturn($ticketSystem);
        $httpClientService->method('getUser')->willReturn($user);

        $this->managerRegistry->method('getManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')
            ->with(UserTicketsystem::class)
            ->willReturn($this->repository);
        $this->repository->method('findOneBy')->willReturn($existingUserTicketSystem);

        $this->tokenEncryptionService->method('encryptToken')
            ->willReturnCallback(static fn ($token) => 'encrypted_' . $token);

        // Should persist the same object, not create a new one
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($existingUserTicketSystem);
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->fetchOAuthAccessToken($httpClientService, 'request_token', 'verifier');
    }

    #[Test]
    public function fetchOAuthRequestTokenHandlesArrayOAuthProblem(): void
    {
        $ticketSystem = $this->createTicketSystem();

        $client = $this->createMock(Client::class);
        // Simulates parse_str creating an array for repeated parameters
        $response = new Response(200, [], 'oauth_problem[]=error1&oauth_problem[]=error2');

        $client->method('post')->willReturn($response);

        $httpClientService = $this->createMock(JiraHttpClientService::class);
        $httpClientService->method('getClient')->willReturn($client);
        $httpClientService->method('getTicketSystem')->willReturn($ticketSystem);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('OAuth problem:');

        $this->service->fetchOAuthRequestToken($httpClientService);
    }
}

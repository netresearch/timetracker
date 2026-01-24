<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiInvalidResourceException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\Integration\Jira\JiraAuthenticationService;
use App\Service\Integration\Jira\JiraHttpClientService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use UnexpectedValueException;

#[CoversClass(JiraHttpClientService::class)]
final class JiraHttpClientServiceTest extends TestCase
{
    private User $user;
    private TicketSystem $ticketSystem;
    private JiraAuthenticationService&MockObject $authService;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->user->setUsername('testuser');
        $this->user->setType('DEV');
        $this->user->setLocale('en');
        $this->user->setAbbr('TU');

        $reflection = new ReflectionClass($this->user);
        $property = $reflection->getProperty('id');
        $property->setValue($this->user, 1);

        $this->ticketSystem = new TicketSystem();
        $this->ticketSystem->setName('Test Jira');
        $this->ticketSystem->setUrl('https://jira.example.com');
        $this->ticketSystem->setLogin('consumer_key');
        $this->ticketSystem->setPrivateKey("-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBALtF5qTq9sC+xCqMhOqr9s4KS6OiGMAoYONiLGM0E/DEMO\n-----END RSA PRIVATE KEY-----");

        $reflection = new ReflectionClass($this->ticketSystem);
        $property = $reflection->getProperty('id');
        $property->setValue($this->ticketSystem, 1);

        $this->authService = $this->createMock(JiraAuthenticationService::class);
    }

    private function createService(): JiraHttpClientService
    {
        return new JiraHttpClientService(
            $this->user,
            $this->ticketSystem,
            $this->authService,
        );
    }

    #[Test]
    public function getUserReturnsUser(): void
    {
        $service = $this->createService();

        $this->assertSame($this->user, $service->getUser());
    }

    #[Test]
    public function getTicketSystemReturnsTicketSystem(): void
    {
        $service = $this->createService();

        $this->assertSame($this->ticketSystem, $service->getTicketSystem());
    }

    #[Test]
    public function getClientWithUserModeGetsTokensFromAuthService(): void
    {
        $this->authService->expects($this->once())
            ->method('getTokens')
            ->with($this->user, $this->ticketSystem)
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->createService();
        $client = $service->getClient('user');

        $this->assertInstanceOf(Client::class, $client);
    }

    #[Test]
    public function getClientWithUserModeThrowsWhenNoTokens(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => '', 'secret' => '']);

        $this->authService->expects($this->once())
            ->method('throwUnauthorizedRedirect')
            ->with($this->ticketSystem)
            ->willThrowException(new JiraApiUnauthorizedException('Unauthorized'));

        $service = $this->createService();

        $this->expectException(JiraApiUnauthorizedException::class);

        $service->getClient('user');
    }

    #[Test]
    public function getClientWithNewModeReturnsEmptyTokens(): void
    {
        // No token fetch should happen for 'new' mode
        $this->authService->expects($this->never())->method('getTokens');

        $service = $this->createService();
        $client = $service->getClient('new');

        $this->assertInstanceOf(Client::class, $client);
    }

    #[Test]
    public function getClientWithRequestModeUsesProvidedToken(): void
    {
        // No token fetch should happen for 'request' mode
        $this->authService->expects($this->never())->method('getTokens');

        $service = $this->createService();
        $client = $service->getClient('request', 'provided_request_token');

        $this->assertInstanceOf(Client::class, $client);
    }

    #[Test]
    public function getClientWithInvalidModeThrowsException(): void
    {
        $service = $this->createService();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid token mode: invalid');

        $service->getClient('invalid');
    }

    #[Test]
    public function getClientCachesClientsByTokens(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->createService();

        $client1 = $service->getClient('user');
        $client2 = $service->getClient('user');

        $this->assertSame($client1, $client2);
    }

    #[Test]
    public function getClientThrowsOnMissingPrivateKey(): void
    {
        $this->ticketSystem->setPrivateKey('');

        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->createService();

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('OAuth private key not configured');

        $service->getClient('user');
    }

    #[Test]
    public function doesResourceExistReturnsTrueOn200(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        // Create a partial mock to override getClient
        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $response = new Response(200);

        $clientMock->method('request')
            ->with('HEAD', '/rest/api/latest/issue/TEST-123', ['auth' => 'oauth'])
            ->willReturn($response);

        $service->method('getClient')->willReturn($clientMock);

        $result = $service->doesResourceExist('issue/TEST-123');

        $this->assertTrue($result);
    }

    #[Test]
    public function doesResourceExistReturnsFalseOnException(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $clientMock->method('request')
            ->willThrowException(new ConnectException('Connection failed', new Request('HEAD', '/test')));

        $service->method('getClient')->willReturn($clientMock);

        $result = $service->doesResourceExist('issue/TEST-123');

        $this->assertFalse($result);
    }

    #[Test]
    public function getReturnsDecodedJsonResponse(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $response = new Response(200, [], '{"id": 123, "key": "TEST-123"}');

        $clientMock->method('request')
            ->with('GET', '/rest/api/latest/issue/TEST-123', ['auth' => 'oauth'])
            ->willReturn($response);

        $service->method('getClient')->willReturn($clientMock);

        $result = $service->get('issue/TEST-123');

        $this->assertIsObject($result);
        $this->assertSame(123, $result->id);
        $this->assertSame('TEST-123', $result->key);
    }

    #[Test]
    public function getReturnsEmptyObjectOnEmptyResponse(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $response = new Response(204, [], '');

        $clientMock->method('request')->willReturn($response);
        $service->method('getClient')->willReturn($clientMock);

        $result = $service->get('some/resource');

        $this->assertInstanceOf(stdClass::class, $result);
    }

    #[Test]
    public function postSendsJsonData(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $response = new Response(201, [], '{"id": 456}');

        $clientMock->expects($this->once())
            ->method('request')
            ->with('POST', '/rest/api/latest/issue', [
                'auth' => 'oauth',
                'json' => ['fields' => ['summary' => 'Test issue']],
            ])
            ->willReturn($response);

        $service->method('getClient')->willReturn($clientMock);

        $result = $service->post('issue', ['fields' => ['summary' => 'Test issue']]);

        $this->assertSame(456, $result->id);
    }

    #[Test]
    public function putSendsJsonData(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $response = new Response(200, [], '{"id": 789}');

        $clientMock->expects($this->once())
            ->method('request')
            ->with('PUT', '/rest/api/latest/issue/TEST-123', [
                'auth' => 'oauth',
                'json' => ['fields' => ['summary' => 'Updated']],
            ])
            ->willReturn($response);

        $service->method('getClient')->willReturn($clientMock);

        $result = $service->put('issue/TEST-123', ['fields' => ['summary' => 'Updated']]);

        $this->assertSame(789, $result->id);
    }

    #[Test]
    public function deleteSendsDeleteRequest(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $response = new Response(204, [], '');

        $clientMock->expects($this->once())
            ->method('request')
            ->with('DELETE', '/rest/api/latest/issue/TEST-123/worklog/456', ['auth' => 'oauth'])
            ->willReturn($response);

        $service->method('getClient')->willReturn($clientMock);

        $result = $service->delete('issue/TEST-123/worklog/456');

        $this->assertInstanceOf(stdClass::class, $result);
    }

    #[Test]
    public function handleGuzzleExceptionThrows401AsUnauthorized(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $this->authService->method('throwUnauthorizedRedirect')
            ->willThrowException(new JiraApiUnauthorizedException('Unauthorized'));

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $request = new Request('GET', '/test');
        $response = new Response(401, [], '{"message": "Unauthorized"}');
        $exception = new RequestException('Unauthorized', $request, $response);

        $clientMock->method('request')->willThrowException($exception);
        $service->method('getClient')->willReturn($clientMock);

        $this->expectException(JiraApiUnauthorizedException::class);

        $service->get('issue/TEST-123');
    }

    #[Test]
    public function handleGuzzleExceptionThrows404AsInvalidResource(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $request = new Request('GET', '/test');
        $response = new Response(404, [], '{"errorMessages": ["Issue Does Not Exist"]}');
        $exception = new RequestException('Not Found', $request, $response);

        $clientMock->method('request')->willThrowException($exception);
        $service->method('getClient')->willReturn($clientMock);

        $this->expectException(JiraApiInvalidResourceException::class);
        $this->expectExceptionMessage('Resource not found');

        $service->get('issue/INVALID-123');
    }

    #[Test]
    public function handleGuzzleExceptionExtractsErrorMessages(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $request = new Request('POST', '/test');
        $response = new Response(400, [], '{"errorMessages": ["Field required", "Invalid value"]}');
        $exception = new RequestException('Bad Request', $request, $response);

        $clientMock->method('request')->willThrowException($exception);
        $service->method('getClient')->willReturn($clientMock);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessageMatches('/Field required.*Invalid value/');

        $service->post('issue', ['fields' => []]);
    }

    #[Test]
    public function handleGuzzleExceptionExtractsErrorsArray(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $request = new Request('POST', '/test');
        $response = new Response(400, [], '{"errors": {"summary": "Summary is required", "priority": "Invalid priority"}}');
        $exception = new RequestException('Bad Request', $request, $response);

        $clientMock->method('request')->willThrowException($exception);
        $service->method('getClient')->willReturn($clientMock);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessageMatches('/Summary is required.*Invalid priority/');

        $service->post('issue', ['fields' => []]);
    }

    #[Test]
    public function handleGuzzleExceptionWithEmptyResponseBody(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $request = new Request('GET', '/test');
        $response = new Response(500, [], '');
        $exception = new RequestException('Server Error', $request, $response);

        $clientMock->method('request')->willThrowException($exception);
        $service->method('getClient')->willReturn($clientMock);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Empty response');

        $service->get('issue/TEST-123');
    }

    #[Test]
    public function handleGuzzleExceptionWithNetworkError(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $request = new Request('GET', '/test');
        $exception = new ConnectException('Connection timed out', $request);

        $clientMock->method('request')->willThrowException($exception);
        $service->method('getClient')->willReturn($clientMock);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Network error connecting to Jira');

        $service->get('issue/TEST-123');
    }

    #[Test]
    public function handleGuzzleExceptionWithInvalidJsonResponse(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $request = new Request('POST', '/test');
        $response = new Response(400, [], 'not valid json {');
        $exception = new RequestException('Bad Request', $request, $response);

        $clientMock->method('request')->willThrowException($exception);
        $service->method('getClient')->willReturn($clientMock);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('not valid json');

        $service->post('issue', []);
    }

    #[Test]
    public function sendRequestThrowsOnInvalidJsonResponse(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $response = new Response(200, [], '{"invalid json');

        $clientMock->method('request')->willReturn($response);
        $service->method('getClient')->willReturn($clientMock);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Invalid JSON response from Jira');

        $service->get('issue/TEST-123');
    }

    #[Test]
    public function urlWithLeadingSlashIsNormalized(): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $response = new Response(200, [], '{}');

        // Both URLs should result in the same API call
        $clientMock->expects($this->once())
            ->method('request')
            ->with('GET', '/rest/api/latest/issue/TEST-123', ['auth' => 'oauth'])
            ->willReturn($response);

        $service->method('getClient')->willReturn($clientMock);

        $service->get('/issue/TEST-123');
    }

    #[Test]
    #[DataProvider('provideStatusCodes')]
    public function handlesDifferentStatusCodes(int $statusCode, string $expectedExceptionClass): void
    {
        $this->authService->method('getTokens')
            ->willReturn(['token' => 'user_token', 'secret' => 'user_secret']);

        if (401 === $statusCode) {
            $this->authService->method('throwUnauthorizedRedirect')
                ->willThrowException(new JiraApiUnauthorizedException('Unauthorized'));
        }

        $service = $this->getMockBuilder(JiraHttpClientService::class)
            ->setConstructorArgs([$this->user, $this->ticketSystem, $this->authService])
            ->onlyMethods(['getClient'])
            ->getMock();

        $clientMock = $this->createMock(Client::class);
        $request = new Request('GET', '/test');
        $response = new Response($statusCode, [], '{"errorMessages": ["Error"]}');
        $exception = new RequestException('Error', $request, $response);

        $clientMock->method('request')->willThrowException($exception);
        $service->method('getClient')->willReturn($clientMock);

        $this->expectException($expectedExceptionClass);

        $service->get('issue/TEST-123');
    }

    /**
     * @return array<string, array{int, class-string}>
     */
    public static function provideStatusCodes(): array
    {
        return [
            '401 Unauthorized' => [401, JiraApiUnauthorizedException::class],
            '404 Not Found' => [404, JiraApiInvalidResourceException::class],
            '400 Bad Request' => [400, JiraApiException::class],
            '403 Forbidden' => [403, JiraApiException::class],
            '500 Server Error' => [500, JiraApiException::class],
            '503 Service Unavailable' => [503, JiraApiException::class],
        ];
    }
}

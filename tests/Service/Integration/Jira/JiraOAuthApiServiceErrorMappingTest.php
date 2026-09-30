<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiInvalidResourceException;
use App\Service\Integration\Jira\JiraOAuthApiService;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;
use Tests\Fixtures\FailingClientJiraOAuthApiService;
use Tests\Traits\TokenEncryptionTestTrait;

/**
 * How the three request paths of the legacy Jira API service (tenant GET, single-object
 * request, list request) map a Guzzle failure: 404 becomes an invalid-resource exception,
 * anything else a generic API exception that keeps the status code and the cause.
 *
 * @internal
 */
#[CoversClass(JiraOAuthApiService::class)]
final class JiraOAuthApiServiceErrorMappingTest extends TestCase
{
    use TokenEncryptionTestTrait;

    /**
     * @return iterable<string, array{string, string}> entry point => url it reports on
     */
    public static function requestPaths(): iterable
    {
        yield 'tenant GET' => ['tenant', '/rest/tempo-accounts/1/accounts'];
        yield 'single-object request' => ['object', 'issue/ABC-1'];
        yield 'list request' => ['list', 'issue/ABC-1/worklog/list'];
    }

    #[DataProvider('requestPaths')]
    public function testNotFoundBecomesInvalidResourceException(string $path, string $url): void
    {
        $service = $this->serviceFailingWith(404, 'gone');

        try {
            $this->call($service, $path, $url);
            self::fail('Expected JiraApiInvalidResourceException');
        } catch (JiraApiInvalidResourceException $exception) {
            self::assertSame('Jira: 404 - Resource is not available: (' . $url . ')', $exception->getMessage());
            self::assertSame(404, $exception->getCode());
            self::assertInstanceOf(GuzzleException::class, $exception->getPrevious());
        }
    }

    #[DataProvider('requestPaths')]
    public function testOtherFailuresBecomeGenericApiException(string $path, string $url): void
    {
        $service = $this->serviceFailingWith(500, 'upstream exploded');

        try {
            $this->call($service, $path, $url);
            self::fail('Expected JiraApiException');
        } catch (JiraApiException $exception) {
            self::assertNotInstanceOf(JiraApiInvalidResourceException::class, $exception);
            self::assertSame('Jira: Unknown Guzzle exception: upstream exploded', $exception->getMessage());
            self::assertSame(500, $exception->getCode());
            self::assertInstanceOf(GuzzleException::class, $exception->getPrevious());
        }
    }

    private function call(FailingClientJiraOAuthApiService $service, string $path, string $url): mixed
    {
        return match ($path) {
            'tenant' => $service->getFromTenant($url),
            'object' => $service->requestObject($url),
            default => $service->requestList($url),
        };
    }

    private function serviceFailingWith(int $status, string $message): FailingClientJiraOAuthApiService
    {
        $client = self::createStub(Client::class);
        $client->method('request')->willThrowException(
            new RequestException($message, new Request('GET', '/x'), new Response($status)),
        );

        return new FailingClientJiraOAuthApiService(
            self::createStub(User::class),
            self::createStub(TicketSystem::class),
            self::createStub(ManagerRegistry::class),
            self::createStub(RouterInterface::class),
            $this->createTokenEncryptionService(),
            $client,
        );
    }
}

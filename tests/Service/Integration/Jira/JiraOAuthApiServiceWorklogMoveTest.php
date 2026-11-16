<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\Integration\Jira\JiraOAuthApiService;
use DateTime;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use JsonException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use Symfony\Component\Routing\RouterInterface;
use Tests\Fixtures\FailingClientJiraOAuthApiService;
use Tests\Traits\TokenEncryptionTestTrait;
use Throwable;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * A worklog DELETE that Jira answers with 404 on the entry's ticket looks the worklog up
 * by id (POST worklog/list) and deletes it where it really is, but only when the found
 * worklog is provably the entry's: worklog ids are unique per Jira instance, so the same
 * number on another instance is an unrelated worklog (#941).
 *
 * @internal
 */
#[CoversClass(JiraOAuthApiService::class)]
#[AllowMockObjectsWithoutExpectations]
final class JiraOAuthApiServiceWorklogMoveTest extends TestCase
{
    use TokenEncryptionTestTrait;

    private const string ME = '{"name":"jdoe","accountId":null}';

    /** @var list<string> method and URI of each request sent, in order */
    private array $sent = [];

    /**
     * @return iterable<string, array{list<Response>, ?int, list<string>}>
     */
    public static function settledLookups(): iterable
    {
        $notFound = new Response(404, [], '{}');

        yield 'found on another issue and provably the entry\'s: deleted there' => [
            [$notFound, self::listed(self::worklog()), new Response(200, [], self::ME), new Response(204, [], '')],
            null,
            ['DELETE issue/INT-5/worklog/42', 'POST worklog/list', 'GET myself', 'DELETE issue/151932/worklog/42'],
        ];

        yield 'not listed: today\'s behaviour, id kept' => [
            [$notFound, new Response(200, [], '[]')],
            42,
            ['DELETE issue/INT-5/worklog/42', 'POST worklog/list'],
        ];

        yield 'same id, another author (a worklog of another Jira instance): never deleted' => [
            [$notFound, self::listed(self::worklog(author: 'someone-else')), new Response(200, [], self::ME)],
            42,
            ['DELETE issue/INT-5/worklog/42', 'POST worklog/list', 'GET myself'],
        ];

        yield 'same id, another duration: never deleted' => [
            [$notFound, self::listed(self::worklog(seconds: 7200))],
            42,
            ['DELETE issue/INT-5/worklog/42', 'POST worklog/list'],
        ];

        yield 'same id, another start: never deleted' => [
            [$notFound, self::listed(self::worklog(started: '2026-06-15 10:00'))],
            42,
            ['DELETE issue/INT-5/worklog/42', 'POST worklog/list'],
        ];
    }

    /**
     * @param list<Response> $responses
     * @param list<string>   $expectedRequests
     */
    #[DataProvider('settledLookups')]
    public function testA404OnTheEntrysTicketIsSettledByTheLookup(array $responses, ?int $expectedWorklogId, array $expectedRequests): void
    {
        $entry = $this->bookedEntry();

        self::assertTrue($this->service($responses)->deleteEntryJiraWorkLog($entry));
        self::assertSame($expectedWorklogId, $entry->getWorklogId());
        self::assertSame($expectedRequests, $this->sentRequests());
    }

    /**
     * @return iterable<string, array{list<Response>, class-string<Throwable>, list<string>}>
     */
    public static function unfinishedDeletes(): iterable
    {
        $lookedUp = ['DELETE issue/INT-5/worklog/42', 'POST worklog/list'];

        yield 'lookup answers 500' => [[new Response(500, [], 'boom')], JiraApiException::class, $lookedUp];
        yield 'lookup answers 401' => [[new Response(401, [], 'oauth_problem=token_rejected')], JiraApiUnauthorizedException::class, $lookedUp];
        yield 'lookup answers an object, not a list' => [[new Response(200, [], '{"errorMessages":[]}')], JiraApiException::class, $lookedUp];
        yield 'lookup lists another worklog' => [[self::listed(self::worklog(id: '43'))], JiraApiException::class, $lookedUp];
        yield 'lookup lists the worklog without its issue' => [[self::listed(self::worklog(issueId: null))], JiraApiException::class, $lookedUp];
        yield 'lookup answers no JSON' => [[new Response(200, [], '<html>gateway</html>')], JsonException::class, $lookedUp];
        yield 'found, but the DELETE on its issue fails' => [
            [self::listed(self::worklog()), new Response(200, [], self::ME), new Response(500, [], 'boom')],
            JiraApiException::class,
            [...$lookedUp, 'GET myself', 'DELETE issue/151932/worklog/42'],
        ];
    }

    /**
     * Every path that cannot finish the delete propagates and keeps the worklog id.
     *
     * @param list<Response>          $responses         answers after the 404 on the entry's ticket
     * @param class-string<Throwable> $expectedException
     * @param list<string>            $expectedRequests
     */
    #[DataProvider('unfinishedDeletes')]
    public function testAnUnfinishedDeletePropagatesAndKeepsTheId(array $responses, string $expectedException, array $expectedRequests): void
    {
        $entry = $this->bookedEntry();
        $service = $this->service([new Response(404, [], '{}'), ...$responses]);

        $thrown = null;
        try {
            $service->deleteEntryJiraWorkLog($entry);
        } catch (Throwable $throwable) {
            $thrown = $throwable;
        }

        self::assertInstanceOf($expectedException, $thrown, 'An unfinished delete must propagate');
        self::assertSame(42, $entry->getWorklogId());
        self::assertSame($expectedRequests, $this->sentRequests());
    }

    private static function worklog(
        string $id = '42',
        ?string $issueId = '151932',
        string $author = 'jdoe',
        int $seconds = 3600,
        string $started = '2026-06-15 09:00',
    ): object {
        return (object) [
            'id' => $id,
            'issueId' => $issueId,
            'author' => (object) ['name' => $author],
            'self' => 'https://jira.example.com/rest/api/2/issue/151932/worklog/' . $id,
            'started' => new DateTime($started)->format('Y-m-d\TH:i:s.000O'),
            'timeSpentSeconds' => $seconds,
            'updated' => '2026-06-15T12:00:00.000+0200',
        ];
    }

    private static function listed(object $worklog): Response
    {
        return new Response(200, [], (string) json_encode([$worklog], JSON_THROW_ON_ERROR));
    }

    private function bookedEntry(): Entry
    {
        return new Entry()
            ->setTicket('INT-5')
            ->setDay('2026-06-15')
            ->setStart('09:00')
            ->setDuration(60)
            ->setWorklogId(42);
    }

    /**
     * @return list<string>
     */
    private function sentRequests(): array
    {
        return $this->sent;
    }

    /**
     * @param list<Response> $responses
     */
    private function service(array $responses): JiraOAuthApiService
    {
        $ticketSystem = new TicketSystem();
        $ticketSystem->setUrl('https://jira.example.com');
        $ticketSystem->setBookTime(true);
        new ReflectionProperty(TicketSystem::class, 'id')->setValue($ticketSystem, 7);

        $repository = self::createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $managerRegistry = self::createStub(ManagerRegistry::class);
        $managerRegistry->method('getRepository')->willReturn($repository);

        $this->sent = [];
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->sent[] = $request->getMethod() . ' ' . (string) $request->getUri();

            return $request;
        }));
        $client = new Client(['handler' => $handlerStack]);

        return new FailingClientJiraOAuthApiService(new User(), $ticketSystem, $managerRegistry, self::createStub(RouterInterface::class), $this->createTokenEncryptionService(), $client);
    }
}

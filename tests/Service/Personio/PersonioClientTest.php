<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Personio;

use App\DTO\Personio\AttendancePeriod;
use App\Exception\Personio\PersonioApiException;
use App\Service\Personio\PersonioClient;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function is_array;
use function json_encode;

/**
 * Unit tests for the Personio v2 client-credentials API client.
 *
 * @internal
 */
#[CoversClass(PersonioClient::class)]
#[CoversClass(AttendancePeriod::class)]
#[CoversClass(PersonioApiException::class)]
final class PersonioClientTest extends TestCase
{
    /** @var list<array{method: string, path: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var callable(string, string, array<string, mixed>): Response */
    private $handler;

    protected function setUp(): void
    {
        $this->requests = [];
        // Default handler: 404 for anything a test did not stub.
        $this->handler = static fn (): Response => new Response(404, [], '{}');
    }

    public function testCreateAttendancePeriodPostsAndReturnsId(): void
    {
        $this->handler = static fn (string $method, string $path): Response => new Response(
            201,
            [],
            (string) json_encode(['_data' => ['id' => '1001']]),
        );

        $client = $this->createClient();
        $id = $client->createAttendancePeriod(
            'emp-1',
            'WORK',
            '2026-07-01T09:00:00+02:00',
            '2026-07-01T17:00:00+02:00',
        );

        self::assertSame('1001', $id);

        $post = $this->apiRequests()[0];
        self::assertSame('POST', $post['method']);
        self::assertSame('v2/attendance-periods', $post['path']);
        $body = $post['options']['json'] ?? null;
        self::assertIsArray($body);
        self::assertSame('WORK', $body['type'] ?? null);
        $person = $body['person'] ?? null;
        self::assertIsArray($person);
        self::assertSame('emp-1', $person['id'] ?? null);
        $start = $body['start'] ?? null;
        self::assertIsArray($start);
        self::assertSame('2026-07-01T09:00:00+02:00', $start['date_time'] ?? null);
        $end = $body['end'] ?? null;
        self::assertIsArray($end);
        self::assertSame('2026-07-01T17:00:00+02:00', $end['date_time'] ?? null);
    }

    public function testListAttendancePeriodsParsesAndPaginates(): void
    {
        $this->handler = static function (string $method, string $path, array $options): Response {
            $query = $options['query'] ?? [];
            $cursor = is_array($query) ? ($query['cursor'] ?? null) : null;
            if (null === $cursor) {
                return new Response(200, [], (string) json_encode([
                    '_data' => [[
                        'id' => '1001',
                        'person' => ['id' => 'emp-1'],
                        'type' => 'WORK',
                        'start' => ['date_time' => '2026-07-01T09:00:00+02:00'],
                        'end' => ['date_time' => '2026-07-01T12:30:00+02:00'],
                        'status' => 'CONFIRMED',
                        'comment' => 'morning',
                    ]],
                    '_meta' => ['links' => ['next' => ['href' => 'https://api.personio.test/v2/attendance-periods?cursor=CUR2']]],
                ]));
            }

            return new Response(200, [], (string) json_encode([
                '_data' => [[
                    'id' => '1002',
                    'person' => ['id' => 'emp-1'],
                    'type' => 'WORK',
                    'start' => ['date_time' => '2026-07-01T13:00:00+02:00'],
                    'end' => ['date_time' => '2026-07-01T17:30:00+02:00'],
                    'status' => null,
                    'comment' => null,
                ]],
                '_meta' => ['links' => ['next' => null]],
            ]));
        };

        $client = $this->createClient();
        $periods = $client->listAttendancePeriods(
            'emp-1',
            new DateTimeImmutable('2026-07-01T00:00:00+02:00'),
            new DateTimeImmutable('2026-07-01T23:59:59+02:00'),
        );

        self::assertCount(2, $periods);
        self::assertContainsOnlyInstancesOf(AttendancePeriod::class, $periods);

        self::assertSame('1001', $periods[0]->id);
        self::assertSame('emp-1', $periods[0]->personId);
        self::assertSame('WORK', $periods[0]->type);
        self::assertSame('2026-07-01T09:00:00+02:00', $periods[0]->startDateTime);
        self::assertSame('2026-07-01T12:30:00+02:00', $periods[0]->endDateTime);
        self::assertSame('CONFIRMED', $periods[0]->status);
        self::assertSame('morning', $periods[0]->comment);
        self::assertTrue($periods[0]->isApproved());

        self::assertSame('1002', $periods[1]->id);
        self::assertNull($periods[1]->status);
        self::assertFalse($periods[1]->isApproved());

        // Two pages were requested; the second carried the extracted cursor.
        $listRequests = $this->apiRequests();
        self::assertCount(2, $listRequests);
        $firstQuery = $listRequests[0]['options']['query'] ?? null;
        self::assertIsArray($firstQuery);
        self::assertSame('emp-1', $firstQuery['person.id'] ?? null);
        $secondQuery = $listRequests[1]['options']['query'] ?? null;
        self::assertIsArray($secondQuery);
        self::assertSame('CUR2', $secondQuery['cursor'] ?? null);
    }

    public function testUpdateAndDeleteHitCorrectPaths(): void
    {
        $this->handler = static fn (): Response => new Response(204, [], '');

        $client = $this->createClient();
        $client->updateAttendancePeriod('1001', '2026-07-01T09:00:00+02:00', '2026-07-01T18:00:00+02:00');
        $client->deleteAttendancePeriod('1001');

        $apiRequests = $this->apiRequests();
        self::assertSame('PATCH', $apiRequests[0]['method']);
        self::assertSame('v2/attendance-periods/1001', $apiRequests[0]['path']);
        $patchBody = $apiRequests[0]['options']['json'] ?? null;
        self::assertIsArray($patchBody);
        $patchEnd = $patchBody['end'] ?? null;
        self::assertIsArray($patchEnd);
        self::assertSame('2026-07-01T18:00:00+02:00', $patchEnd['date_time'] ?? null);

        self::assertSame('DELETE', $apiRequests[1]['method']);
        self::assertSame('v2/attendance-periods/1001', $apiRequests[1]['path']);
    }

    public function testTokenFetchedOnceAndReused(): void
    {
        $this->handler = static fn (): Response => new Response(204, [], '');

        $client = $this->createClient();
        $client->deleteAttendancePeriod('1001');
        $client->deleteAttendancePeriod('1002');

        $tokenRequests = array_filter(
            $this->requests,
            static fn (array $request): bool => 'v2/auth/token' === $request['path'],
        );
        self::assertCount(1, $tokenRequests);
    }

    public function testNon2xxThrowsPersonioApiExceptionWithStatus(): void
    {
        $this->handler = static fn (): Response => new Response(403, [], (string) json_encode(['error' => 'forbidden']));

        $client = $this->createClient();

        try {
            $client->deleteAttendancePeriod('1001');
            self::fail('Expected PersonioApiException');
        } catch (PersonioApiException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function testRetriesOn429ThenSucceeds(): void
    {
        $attempts = 0;
        $this->handler = static function () use (&$attempts): Response {
            ++$attempts;
            if (1 === $attempts) {
                return new Response(429, ['Retry-After' => '1'], '');
            }

            return new Response(201, [], (string) json_encode(['_data' => ['id' => '1001']]));
        };

        $client = $this->createClient();
        $id = $client->createAttendancePeriod('emp-1', 'WORK', '2026-07-01T09:00:00+02:00', '2026-07-01T17:00:00+02:00');

        self::assertSame('1001', $id);
        self::assertSame(2, $attempts, 'The request was retried once after the 429.');
    }

    /**
     * Requests to the API (everything except the auth-token endpoint).
     *
     * @return list<array{method: string, path: string, options: array<string, mixed>}>
     */
    private function apiRequests(): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => 'v2/auth/token' !== $request['path'],
        ));
    }

    private function createClient(): PersonioClient
    {
        $test = $this;

        return new class('https://api.personio.test/', 'client-id', 'plain-secret', $test) extends PersonioClient {
            public function __construct(
                string $baseUrl,
                string $clientId,
                string $clientSecret,
                private readonly PersonioClientTest $test,
            ) {
                parent::__construct($baseUrl, $clientId, $clientSecret);
            }

            protected function createHttpClient(array $config): Client
            {
                return $this->test->routeClient();
            }

            protected function delay(int $seconds): void
            {
                // No real sleep in tests.
            }
        };
    }

    /**
     * Test seam: a stub Guzzle client that records every request and answers the
     * auth-token endpoint centrally, delegating API responses to the per-test handler.
     */
    public function routeClient(): Client
    {
        $client = self::createStub(Client::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $path, array $options = []): Response {
                /** @var array<string, mixed> $options */
                $this->requests[] = ['method' => $method, 'path' => $path, 'options' => $options];

                if ('v2/auth/token' === $path) {
                    return new Response(200, [], (string) json_encode(['access_token' => 'tok', 'expires_in' => 3600]));
                }

                return ($this->handler)($method, $path, $options);
            },
        );

        return $client;
    }
}

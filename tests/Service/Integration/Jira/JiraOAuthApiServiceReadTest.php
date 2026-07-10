<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\DTO\Jira\JiraWorkLog;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiInvalidResourceException;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Security\TokenEncryptionService;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Routing\RouterInterface;
use Tests\Traits\TokenEncryptionTestTrait;

/**
 * Unit tests for the ADR-023 read methods on the legacy Jira API service.
 *
 * @internal
 */
#[CoversClass(JiraOAuthApiService::class)]
final class JiraOAuthApiServiceReadTest extends TestCase
{
    use TokenEncryptionTestTrait;

    /**
     * @param array<string, mixed>        $getResponses   url => decoded response (the string '404' simulates a 404)
     * @param array<string, list<object>> $arrayResponses url => decoded array response
     */
    private function serviceWithCannedResponses(array $getResponses, mixed $searchResponse = null, array $arrayResponses = []): JiraOAuthApiService
    {
        $user = self::createStub(User::class);
        $ticketSystem = self::createStub(TicketSystem::class);
        $managerRegistry = self::createStub(ManagerRegistry::class);
        $router = self::createStub(RouterInterface::class);
        $tokenEncryptionService = $this->createTokenEncryptionService();

        return new class($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService, $getResponses, $searchResponse, $arrayResponses) extends JiraOAuthApiService {
            /**
             * @param array<string, mixed>        $getResponses
             * @param array<string, list<object>> $arrayResponses
             */
            public function __construct(
                User $user,
                TicketSystem $ticketSystem,
                ManagerRegistry $managerRegistry,
                RouterInterface $router,
                TokenEncryptionService $tokenEncryptionService,
                private readonly array $getResponses,
                private readonly mixed $searchResponse,
                private readonly array $arrayResponses,
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

            /**
             * @param array<string, mixed> $data
             *
             * @return list<object>
             */
            protected function getResponseArray(string $url, array $data = []): array
            {
                return $this->arrayResponses[$url] ?? [];
            }

            /**
             * @param array<int, string> $fields
             */
            public function searchTicket(string $jql, array $fields, int $limit = 1): mixed
            {
                return $this->searchResponse;
            }
        };
    }

    public function testGetIssueWorklogsParsesWorklogArray(): void
    {
        $service = $this->serviceWithCannedResponses([
            'issue/ABC-1/worklog?maxResults=1000&startAt=0' => (object) [
                'worklogs' => [
                    (object) ['id' => '1', 'started' => '2026-07-08T09:00:00.000+0200', 'timeSpentSeconds' => 3600],
                    (object) ['id' => '2', 'started' => '2026-07-08T11:00:00.000+0200', 'timeSpentSeconds' => 1800],
                ],
            ],
        ]);

        $workLogs = $service->getIssueWorklogs('ABC-1');

        self::assertCount(2, $workLogs);
        self::assertContainsOnlyInstancesOf(JiraWorkLog::class, $workLogs);
        self::assertSame(1, $workLogs[0]->id);
    }

    public function testGetIssueWorklogsToleratesMalformedResponse(): void
    {
        $service = $this->serviceWithCannedResponses(['issue/ABC-1/worklog?maxResults=1000&startAt=0' => (object) ['unexpected' => true]]);

        self::assertSame([], $service->getIssueWorklogs('ABC-1'));
    }

    public function testGetIssueWorklogsPaginatesUntilTotal(): void
    {
        $service = $this->serviceWithCannedResponses([
            'issue/ABC-1/worklog?maxResults=1000&startAt=0' => (object) [
                'total' => 3,
                'worklogs' => [
                    (object) ['id' => '1', 'started' => '2026-07-08T09:00:00.000+0200', 'timeSpentSeconds' => 3600],
                    (object) ['id' => '2', 'started' => '2026-07-08T11:00:00.000+0200', 'timeSpentSeconds' => 1800],
                ],
            ],
            'issue/ABC-1/worklog?maxResults=1000&startAt=2' => (object) [
                'total' => 3,
                'worklogs' => [
                    (object) ['id' => '3', 'started' => '2026-07-08T13:00:00.000+0200', 'timeSpentSeconds' => 900],
                ],
            ],
        ]);

        $workLogs = $service->getIssueWorklogs('ABC-1');

        self::assertCount(3, $workLogs);
        self::assertSame([1, 2, 3], array_map(static fn (JiraWorkLog $workLog): ?int => $workLog->id, $workLogs));
    }

    public function testSearchIssueKeysCollectsKeysAndDetectsTruncation(): void
    {
        $searchResponse = (object) [
            'total' => 700,
            'issues' => [(object) ['key' => 'ABC-1'], (object) ['key' => 'ABC-2']],
        ];

        $result = $this->serviceWithCannedResponses([], $searchResponse)->searchIssueKeysWithWorklogs('worklogAuthor = currentUser()', 500);

        self::assertSame(['ABC-1', 'ABC-2'], $result->keys);
        self::assertTrue($result->truncated);
    }

    public function testSearchIssueKeysNotTruncatedWhenComplete(): void
    {
        $searchResponse = (object) ['total' => 2, 'issues' => [(object) ['key' => 'ABC-1'], (object) ['key' => 'ABC-2']]];

        $result = $this->serviceWithCannedResponses([], $searchResponse)->searchIssueKeysWithWorklogs('any', 500);

        self::assertFalse($result->truncated);
    }

    public function testGetMyself(): void
    {
        $service = $this->serviceWithCannedResponses(['myself' => (object) ['accountId' => 'abc', 'name' => 'jdoe', 'emailAddress' => 'j@e.de']]);

        $identity = $service->getMyself();

        self::assertSame('abc', $identity->accountId);
        self::assertSame('jdoe', $identity->name);
    }

    public function testGetIssueWorklogReturnsSingleWorklog(): void
    {
        $service = $this->serviceWithCannedResponses([
            'issue/ABC-1/worklog/77' => (object) ['id' => '77', 'started' => '2026-07-08T09:00:00.000+0200', 'timeSpentSeconds' => 600, 'updated' => '2026-07-08T10:00:00.000+0200'],
        ]);

        $workLog = $service->getIssueWorklog('ABC-1', 77);

        self::assertSame(77, $workLog?->id);
        self::assertSame('2026-07-08T10:00:00.000+0200', $workLog->updated);
    }

    public function testGetIssueWorklogReturnsNullOn404(): void
    {
        $service = $this->serviceWithCannedResponses(['issue/ABC-1/worklog/77' => '404']);

        self::assertNull($service->getIssueWorklog('ABC-1', 77));
    }
}

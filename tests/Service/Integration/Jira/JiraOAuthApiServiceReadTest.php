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
     * @param array<string, mixed> $getResponses url => decoded response
     */
    private function serviceWithCannedResponses(array $getResponses, mixed $searchResponse = null): JiraOAuthApiService
    {
        $user = self::createStub(User::class);
        $ticketSystem = self::createStub(TicketSystem::class);
        $managerRegistry = self::createStub(ManagerRegistry::class);
        $router = self::createStub(RouterInterface::class);
        $tokenEncryptionService = $this->createTokenEncryptionService();

        return new class($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService, $getResponses, $searchResponse) extends JiraOAuthApiService {
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
                private readonly mixed $searchResponse,
            ) {
                parent::__construct($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService);
            }

            protected function get(string $url): mixed
            {
                return $this->getResponses[$url] ?? new stdClass();
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
            'issue/ABC-1/worklog?maxResults=1000' => (object) [
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
        $service = $this->serviceWithCannedResponses(['issue/ABC-1/worklog?maxResults=1000' => (object) ['unexpected' => true]]);

        self::assertSame([], $service->getIssueWorklogs('ABC-1'));
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
}

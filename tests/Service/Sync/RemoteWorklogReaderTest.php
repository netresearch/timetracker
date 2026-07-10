<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\DTO\Jira\JiraIssueKeySearchResult;
use App\DTO\Jira\JiraUserIdentity;
use App\DTO\Jira\JiraWorkLog;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Sync\RemoteWorklogNormalizer;
use App\Service\Sync\RemoteWorklogReader;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(RemoteWorklogReader::class)]
#[AllowMockObjectsWithoutExpectations]
final class RemoteWorklogReaderTest extends TestCase
{
    private JiraOAuthApiService&MockObject $api;

    private RemoteWorklogReader $reader;

    /** @var callable(JiraWorkLog): bool */
    private $matchesMe;

    protected function setUp(): void
    {
        $this->api = $this->createMock(JiraOAuthApiService::class);
        $this->reader = new RemoteWorklogReader(new RemoteWorklogNormalizer());
        $myself = new JiraUserIdentity(accountId: 'me');
        $this->matchesMe = static fn (JiraWorkLog $workLog): bool => $myself->matchesWorklogAuthor($workLog);
    }

    /**
     * @param list<string>                     $issueKeys
     * @param array<string, list<JiraWorkLog>> $worklogsByIssue
     */
    private function stubJira(array $issueKeys, array $worklogsByIssue, bool $truncated = false): void
    {
        $this->api->method('searchIssueKeysWithWorklogs')->willReturn(new JiraIssueKeySearchResult($issueKeys, $truncated));
        $this->api->method('getIssueWorklogs')->willReturnCallback(
            static fn (string $key): array => $worklogsByIssue[$key] ?? [],
        );
    }

    /**
     * @return array<int, array{snapshot: \App\ValueObject\Sync\WorklogSnapshot, updated: ?string, author: ?string, issueKey: string}>
     */
    private function read(callable $onNotice): array
    {
        return $this->reader->readForAuthor(
            $this->api,
            $this->matchesMe,
            'worklogAuthor = currentUser()',
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-30'),
            $onNotice,
        );
    }

    public function testReturnsWorklogsKeyedByWorklogId(): void
    {
        $first = new JiraWorkLog(id: 1001, started: '2026-06-15T09:00:00.000+0200', timeSpentSeconds: 3600, updated: '2026-06-15T10:00:00.000+0200', authorAccountId: 'me');
        $second = new JiraWorkLog(id: 2002, started: '2026-06-20T11:00:00.000+0200', timeSpentSeconds: 1800, authorAccountId: 'me');
        $this->stubJira(['ABC-1', 'ABC-2'], ['ABC-1' => [$first], 'ABC-2' => [$second]]);

        $result = $this->read(static fn (): null => null);

        self::assertSame([1001, 2002], array_keys($result));
        self::assertSame('ABC-1', $result[1001]['issueKey']);
        self::assertSame('ABC-2', $result[2002]['snapshot']->issueKey);
        self::assertSame('2026-06-15T10:00:00.000+0200', $result[1001]['updated']);
        self::assertSame('me', $result[1001]['author']);
    }

    public function testAuthorPredicateFiltersForeignWorklogs(): void
    {
        $mine = new JiraWorkLog(id: 1001, started: '2026-06-15T09:00:00.000+0200', timeSpentSeconds: 3600, authorAccountId: 'me');
        $foreign = new JiraWorkLog(id: 3003, started: '2026-06-16T09:00:00.000+0200', timeSpentSeconds: 600, authorAccountId: 'someone-else');
        $this->stubJira(['ABC-1'], ['ABC-1' => [$mine, $foreign]]);

        $result = $this->read(static fn (): null => null);

        self::assertSame([1001], array_keys($result));
    }

    public function testWorklogOutsideRangeIsExcluded(): void
    {
        $before = new JiraWorkLog(id: 4004, started: '2026-05-31T23:00:00.000+0200', timeSpentSeconds: 600, authorAccountId: 'me');
        $after = new JiraWorkLog(id: 5005, started: '2026-07-05T12:00:00.000+0200', timeSpentSeconds: 600, authorAccountId: 'me');
        $this->stubJira(['ABC-1'], ['ABC-1' => [$before, $after]]);

        $result = $this->read(static fn (): null => null);

        self::assertSame([], array_keys($result));
    }

    public function testWorklogWithoutIdIsSkipped(): void
    {
        $noId = new JiraWorkLog(id: null, started: '2026-06-15T09:00:00.000+0200', timeSpentSeconds: 600, authorAccountId: 'me');
        $this->stubJira(['ABC-1'], ['ABC-1' => [$noId]]);

        $result = $this->read(static fn (): null => null);

        self::assertSame([], array_keys($result));
    }

    public function testTruncationEmitsTruncatedNotice(): void
    {
        $this->stubJira(['ABC-1'], ['ABC-1' => []], truncated: true);
        $notices = [];

        $this->read(static function (string $type, ?string $issueKey = null, ?Throwable $throwable = null, ?int $worklogId = null) use (&$notices): void {
            $notices[] = [$type, $issueKey, $throwable, $worklogId];
        });

        self::assertSame([['truncated', null, null, null]], $notices);
    }

    public function testIssueFetchFailureEmitsErrorNoticeAndContinues(): void
    {
        $this->api->method('searchIssueKeysWithWorklogs')->willReturn(new JiraIssueKeySearchResult(['ABC-1', 'ABC-2'], false));
        $healthy = new JiraWorkLog(id: 6006, started: '2026-06-10T14:00:00.000+0200', timeSpentSeconds: 600, authorAccountId: 'me');
        $this->api->method('getIssueWorklogs')->willReturnCallback(
            static function (string $issueKey) use ($healthy): array {
                if ('ABC-1' === $issueKey) {
                    throw new RuntimeException('issue gone');
                }

                return [$healthy];
            },
        );
        $notices = [];

        $result = $this->read(static function (string $type, ?string $issueKey = null, ?Throwable $throwable = null, ?int $worklogId = null) use (&$notices): void {
            $notices[] = [$type, $issueKey, $throwable, $worklogId];
        });

        self::assertSame([6006], array_keys($result));
        self::assertCount(1, $notices);
        self::assertSame('error', $notices[0][0]);
        self::assertSame('ABC-1', $notices[0][1]);
        self::assertInstanceOf(RuntimeException::class, $notices[0][2]);
        self::assertNull($notices[0][3]);
    }

    public function testUnparseableWorklogEmitsErrorNoticeWithWorklogId(): void
    {
        $broken = new JiraWorkLog(id: 7007, started: null, timeSpentSeconds: 600, authorAccountId: 'me');
        $this->stubJira(['ABC-1'], ['ABC-1' => [$broken]]);
        $notices = [];

        $result = $this->read(static function (string $type, ?string $issueKey = null, ?Throwable $throwable = null, ?int $worklogId = null) use (&$notices): void {
            $notices[] = [$type, $issueKey, $throwable, $worklogId];
        });

        self::assertSame([], array_keys($result));
        self::assertCount(1, $notices);
        self::assertSame('error', $notices[0][0]);
        self::assertSame('ABC-1', $notices[0][1]);
        self::assertInstanceOf(Throwable::class, $notices[0][2]);
        self::assertSame(7007, $notices[0][3]);
    }
}

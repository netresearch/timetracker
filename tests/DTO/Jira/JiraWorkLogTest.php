<?php

declare(strict_types=1);

namespace Tests\DTO\Jira;

use App\DTO\Jira\JiraProject;
use App\DTO\Jira\JiraSearchResult;
use App\DTO\Jira\JiraTransition;
use App\DTO\Jira\JiraWorkLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for JiraWorkLog and related DTOs.
 *
 * @internal
 */
#[CoversClass(JiraWorkLog::class)]
#[CoversClass(JiraSearchResult::class)]
#[CoversClass(JiraProject::class)]
#[CoversClass(JiraTransition::class)]
final class JiraWorkLogTest extends TestCase
{
    // ==================== JiraWorkLog tests ====================

    public function testJiraWorkLogConstructorDefaults(): void
    {
        $worklog = new JiraWorkLog();

        self::assertNull($worklog->id);
        self::assertNull($worklog->self);
        self::assertNull($worklog->comment);
        self::assertNull($worklog->started);
        self::assertNull($worklog->timeSpentSeconds);
    }

    public function testJiraWorkLogConstructorWithValues(): void
    {
        $worklog = new JiraWorkLog(
            id: 12345,
            self: 'https://jira.example.com/rest/api/2/issue/100/worklog/12345',
            comment: 'Worked on feature X',
            started: '2025-01-15T09:00:00.000+0000',
            timeSpentSeconds: 7200,
        );

        self::assertSame(12345, $worklog->id);
        self::assertSame('https://jira.example.com/rest/api/2/issue/100/worklog/12345', $worklog->self);
        self::assertSame('Worked on feature X', $worklog->comment);
        self::assertSame('2025-01-15T09:00:00.000+0000', $worklog->started);
        self::assertSame(7200, $worklog->timeSpentSeconds);
    }

    public function testJiraWorkLogFromApiResponseWithFullData(): void
    {
        $response = new stdClass();
        $response->id = 99999;
        $response->self = 'https://jira.example.com/rest/api/2/worklog/99999';
        $response->comment = 'Development work';
        $response->started = '2025-01-20T10:30:00.000+0000';
        $response->timeSpentSeconds = 3600;

        $worklog = JiraWorkLog::fromApiResponse($response);

        self::assertSame(99999, $worklog->id);
        self::assertSame('https://jira.example.com/rest/api/2/worklog/99999', $worklog->self);
        self::assertSame('Development work', $worklog->comment);
        self::assertSame('2025-01-20T10:30:00.000+0000', $worklog->started);
        self::assertSame(3600, $worklog->timeSpentSeconds);
    }

    public function testJiraWorkLogFromApiResponseWithMinimalData(): void
    {
        $response = new stdClass();

        $worklog = JiraWorkLog::fromApiResponse($response);

        self::assertNull($worklog->id);
        self::assertNull($worklog->self);
        self::assertNull($worklog->comment);
        self::assertNull($worklog->started);
        self::assertNull($worklog->timeSpentSeconds);
    }

    public function testJiraWorkLogFromApiResponseConvertsStringId(): void
    {
        $response = new stdClass();
        $response->id = '12345'; // String instead of int
        $response->timeSpentSeconds = '1800'; // String instead of int

        $worklog = JiraWorkLog::fromApiResponse($response);

        self::assertSame(12345, $worklog->id);
        self::assertSame(1800, $worklog->timeSpentSeconds);
    }

    public function testJiraWorkLogHasValidIdReturnsTrueForPositiveId(): void
    {
        $worklog = new JiraWorkLog(id: 1);
        self::assertTrue($worklog->hasValidId());

        $worklog = new JiraWorkLog(id: 999999);
        self::assertTrue($worklog->hasValidId());
    }

    public function testJiraWorkLogHasValidIdReturnsFalseForNullId(): void
    {
        $worklog = new JiraWorkLog(id: null);

        self::assertFalse($worklog->hasValidId());
    }

    public function testJiraWorkLogHasValidIdReturnsFalseForZeroId(): void
    {
        $worklog = new JiraWorkLog(id: 0);

        self::assertFalse($worklog->hasValidId());
    }

    public function testJiraWorkLogHasValidIdReturnsFalseForNegativeId(): void
    {
        $worklog = new JiraWorkLog(id: -1);

        self::assertFalse($worklog->hasValidId());
    }

    // ==================== JiraSearchResult tests ====================

    public function testJiraSearchResultConstructorDefaults(): void
    {
        $result = new JiraSearchResult();

        self::assertSame(0, $result->startAt);
        self::assertSame(0, $result->maxResults);
        self::assertSame(0, $result->total);
        self::assertSame([], $result->issues);
    }

    public function testJiraSearchResultFromApiResponseWithFullData(): void
    {
        $issue1 = new stdClass();
        $issue1->key = 'PROJ-1';

        $issue2 = new stdClass();
        $issue2->key = 'PROJ-2';

        $response = new stdClass();
        $response->startAt = 0;
        $response->maxResults = 50;
        $response->total = 100;
        $response->issues = [$issue1, $issue2];

        $result = JiraSearchResult::fromApiResponse($response);

        self::assertSame(0, $result->startAt);
        self::assertSame(50, $result->maxResults);
        self::assertSame(100, $result->total);
        self::assertCount(2, $result->issues);
        self::assertSame('PROJ-1', $result->issues[0]->key);
        self::assertSame('PROJ-2', $result->issues[1]->key);
    }

    public function testJiraSearchResultFromApiResponseIgnoresNonObjectIssues(): void
    {
        $issue1 = new stdClass();
        $issue1->key = 'VALID-1';

        $response = new stdClass();
        $response->issues = [$issue1, 'invalid', null, ['also-invalid']];

        $result = JiraSearchResult::fromApiResponse($response);

        self::assertCount(1, $result->issues);
        self::assertSame('VALID-1', $result->issues[0]->key);
    }

    public function testJiraSearchResultFromApiResponseHandlesNonIntegerValues(): void
    {
        $response = new stdClass();
        $response->startAt = 'not-an-int';
        $response->maxResults = 3.14;
        $response->total = null;

        $result = JiraSearchResult::fromApiResponse($response);

        self::assertSame(0, $result->startAt);
        self::assertSame(0, $result->maxResults);
        self::assertSame(0, $result->total);
    }

    public function testJiraSearchResultGetIssueKeys(): void
    {
        $issue1 = new stdClass();
        $issue1->key = 'KEY-1';

        $issue2 = new stdClass();
        // No key set

        $issue3 = new stdClass();
        $issue3->key = 'KEY-3';

        $response = new stdClass();
        $response->issues = [$issue1, $issue2, $issue3];

        $result = JiraSearchResult::fromApiResponse($response);
        $keys = $result->getIssueKeys();

        self::assertSame(['KEY-1', 'KEY-3'], $keys);
    }

    public function testJiraSearchResultGetIssueKeysReturnsEmptyForNoIssues(): void
    {
        $result = new JiraSearchResult();

        self::assertSame([], $result->getIssueKeys());
    }

    // ==================== JiraProject tests ====================

    public function testJiraProjectConstructorDefaults(): void
    {
        $project = new JiraProject();

        self::assertNull($project->id);
        self::assertNull($project->key);
        self::assertNull($project->name);
        self::assertNull($project->self);
    }

    public function testJiraProjectFromApiResponseWithFullData(): void
    {
        $response = new stdClass();
        $response->id = 10001;
        $response->key = 'MYPROJ';
        $response->name = 'My Project';
        $response->self = 'https://jira.example.com/rest/api/2/project/10001';

        $project = JiraProject::fromApiResponse($response);

        self::assertSame(10001, $project->id);
        self::assertSame('MYPROJ', $project->key);
        self::assertSame('My Project', $project->name);
        self::assertSame('https://jira.example.com/rest/api/2/project/10001', $project->self);
    }

    public function testJiraProjectFromApiResponseConvertsStringId(): void
    {
        $response = new stdClass();
        $response->id = '10001'; // String instead of int

        $project = JiraProject::fromApiResponse($response);

        self::assertSame(10001, $project->id);
    }

    public function testJiraProjectToArrayWithValues(): void
    {
        $project = new JiraProject(
            id: 123,
            key: 'ABC',
            name: 'ABC Project',
        );

        $array = $project->toArray();

        self::assertSame('ABC', $array['key']);
        self::assertSame('ABC Project', $array['name']);
        self::assertSame('123', $array['id']);
    }

    public function testJiraProjectToArrayWithNullValues(): void
    {
        $project = new JiraProject();

        $array = $project->toArray();

        self::assertSame('', $array['key']);
        self::assertSame('', $array['name']);
        self::assertSame('', $array['id']);
    }

    // ==================== JiraTransition tests ====================

    public function testJiraTransitionConstructorDefaults(): void
    {
        $transition = new JiraTransition();

        self::assertNull($transition->id);
        self::assertNull($transition->name);
        self::assertNull($transition->to);
    }

    public function testJiraTransitionFromApiResponseWithFullData(): void
    {
        $toStatus = new stdClass();
        $toStatus->id = 3;
        $toStatus->name = 'Done';

        $response = new stdClass();
        $response->id = 5;
        $response->name = 'Close Issue';
        $response->to = $toStatus;

        $transition = JiraTransition::fromApiResponse($response);

        self::assertSame(5, $transition->id);
        self::assertSame('Close Issue', $transition->name);
        self::assertNotNull($transition->to);
        self::assertSame(3, $transition->to->id);
        self::assertSame('Done', $transition->to->name);
    }

    public function testJiraTransitionFromApiResponseWithMinimalData(): void
    {
        $response = new stdClass();

        $transition = JiraTransition::fromApiResponse($response);

        self::assertNull($transition->id);
        self::assertNull($transition->name);
        self::assertNull($transition->to);
    }

    public function testJiraTransitionFromApiResponseIgnoresNonObjectTo(): void
    {
        $response = new stdClass();
        $response->id = 1;
        $response->to = 'not-an-object';

        $transition = JiraTransition::fromApiResponse($response);

        self::assertSame(1, $transition->id);
        self::assertNull($transition->to);
    }

    public function testJiraTransitionFromApiResponseConvertsStringId(): void
    {
        $response = new stdClass();
        $response->id = '42'; // String instead of int

        $transition = JiraTransition::fromApiResponse($response);

        self::assertSame(42, $transition->id);
    }
}

<?php

declare(strict_types=1);

namespace Tests\DTO\Jira;

use App\DTO\Jira\JiraAssignee;
use App\DTO\Jira\JiraIssue;
use App\DTO\Jira\JiraIssueFields;
use App\DTO\Jira\JiraIssueType;
use App\DTO\Jira\JiraStatus;
use App\DTO\Jira\JiraSubtask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for JiraIssue DTO.
 *
 * @internal
 */
#[CoversClass(JiraIssue::class)]
#[CoversClass(JiraIssueFields::class)]
#[CoversClass(JiraIssueType::class)]
#[CoversClass(JiraStatus::class)]
#[CoversClass(JiraAssignee::class)]
#[CoversClass(JiraSubtask::class)]
final class JiraIssueTest extends TestCase
{
    // ==================== JiraIssue tests ====================

    public function testJiraIssueConstructorDefaults(): void
    {
        $issue = new JiraIssue();

        self::assertNull($issue->id);
        self::assertNull($issue->key);
        self::assertNull($issue->self);
        self::assertNull($issue->fields);
        self::assertSame([], $issue->subtaskKeys);
    }

    public function testJiraIssueConstructorWithValues(): void
    {
        $fields = new JiraIssueFields(summary: 'Test Issue');
        $issue = new JiraIssue(
            id: 123,
            key: 'TEST-123',
            self: 'https://jira.example.com/rest/api/2/issue/123',
            fields: $fields,
            subtaskKeys: ['TEST-124', 'TEST-125'],
        );

        self::assertSame(123, $issue->id);
        self::assertSame('TEST-123', $issue->key);
        self::assertSame('https://jira.example.com/rest/api/2/issue/123', $issue->self);
        self::assertSame($fields, $issue->fields);
        self::assertSame(['TEST-124', 'TEST-125'], $issue->subtaskKeys);
    }

    public function testJiraIssueFromApiResponseWithFullData(): void
    {
        $response = new stdClass();
        $response->id = 12345;
        $response->key = 'PROJ-100';
        $response->self = 'https://jira.example.com/rest/api/2/issue/12345';
        $response->fields = new stdClass();
        $response->fields->summary = 'Test Issue Summary';
        $response->fields->description = 'Test description';

        $issue = JiraIssue::fromApiResponse($response);

        self::assertSame(12345, $issue->id);
        self::assertSame('PROJ-100', $issue->key);
        self::assertSame('https://jira.example.com/rest/api/2/issue/12345', $issue->self);
        self::assertInstanceOf(JiraIssueFields::class, $issue->fields);
        self::assertSame('Test Issue Summary', $issue->fields->summary);
    }

    public function testJiraIssueFromApiResponseWithMinimalData(): void
    {
        $response = new stdClass();

        $issue = JiraIssue::fromApiResponse($response);

        self::assertNull($issue->id);
        self::assertNull($issue->key);
        self::assertNull($issue->self);
        self::assertNull($issue->fields);
        self::assertSame([], $issue->subtaskKeys);
    }

    public function testJiraIssueFromApiResponseWithNonObjectFields(): void
    {
        $response = new stdClass();
        $response->id = '12345'; // String should be converted to int
        $response->key = 'PROJ-100';
        $response->fields = 'not-an-object'; // Invalid

        $issue = JiraIssue::fromApiResponse($response);

        self::assertSame(12345, $issue->id);
        self::assertSame('PROJ-100', $issue->key);
        self::assertNull($issue->fields);
    }

    public function testJiraIssueFromApiResponseWithSubtasks(): void
    {
        $subtask1 = new stdClass();
        $subtask1->key = 'PROJ-101';

        $subtask2 = new stdClass();
        $subtask2->key = 'PROJ-102';

        $fields = new stdClass();
        $fields->subtasks = [$subtask1, $subtask2];

        $response = new stdClass();
        $response->fields = $fields;

        $issue = JiraIssue::fromApiResponse($response);

        self::assertSame(['PROJ-101', 'PROJ-102'], $issue->subtaskKeys);
    }

    public function testJiraIssueIsEpicReturnsTrueForEpicType(): void
    {
        $issuetype = new JiraIssueType(name: 'Epic');
        $fields = new JiraIssueFields(issuetype: $issuetype);
        $issue = new JiraIssue(fields: $fields);

        self::assertTrue($issue->isEpic());
    }

    public function testJiraIssueIsEpicReturnsFalseForNonEpicType(): void
    {
        $issuetype = new JiraIssueType(name: 'Story');
        $fields = new JiraIssueFields(issuetype: $issuetype);
        $issue = new JiraIssue(fields: $fields);

        self::assertFalse($issue->isEpic());
    }

    public function testJiraIssueIsEpicReturnsFalseWithNoFields(): void
    {
        $issue = new JiraIssue();

        self::assertFalse($issue->isEpic());
    }

    public function testJiraIssueGetSummaryReturnsValue(): void
    {
        $fields = new JiraIssueFields(summary: 'My Issue Title');
        $issue = new JiraIssue(fields: $fields);

        self::assertSame('My Issue Title', $issue->getSummary());
    }

    public function testJiraIssueGetSummaryReturnsNullWithNoFields(): void
    {
        $issue = new JiraIssue();

        self::assertNull($issue->getSummary());
    }

    // ==================== JiraIssueFields tests ====================

    public function testJiraIssueFieldsConstructorDefaults(): void
    {
        $fields = new JiraIssueFields();

        self::assertNull($fields->summary);
        self::assertNull($fields->description);
        self::assertNull($fields->issuetype);
        self::assertNull($fields->status);
        self::assertNull($fields->assignee);
        self::assertSame([], $fields->subtasks);
    }

    public function testJiraIssueFieldsFromApiResponseWithFullData(): void
    {
        $issuetype = new stdClass();
        $issuetype->id = 1;
        $issuetype->name = 'Bug';

        $status = new stdClass();
        $status->id = 2;
        $status->name = 'Open';

        $assignee = new stdClass();
        $assignee->accountId = 'abc123';
        $assignee->displayName = 'John Doe';

        $response = new stdClass();
        $response->summary = 'Test Summary';
        $response->description = 'Test Description';
        $response->issuetype = $issuetype;
        $response->status = $status;
        $response->assignee = $assignee;

        $fields = JiraIssueFields::fromApiResponse($response);

        self::assertSame('Test Summary', $fields->summary);
        self::assertSame('Test Description', $fields->description);
        self::assertInstanceOf(JiraIssueType::class, $fields->issuetype);
        self::assertSame('Bug', $fields->issuetype->name);
        self::assertInstanceOf(JiraStatus::class, $fields->status);
        self::assertSame('Open', $fields->status->name);
        self::assertInstanceOf(JiraAssignee::class, $fields->assignee);
        self::assertSame('John Doe', $fields->assignee->displayName);
    }

    public function testJiraIssueFieldsFromApiResponseWithNonObjectNestedData(): void
    {
        $response = new stdClass();
        $response->issuetype = 'not-an-object';
        $response->status = null;
        $response->assignee = [];

        $fields = JiraIssueFields::fromApiResponse($response);

        self::assertNull($fields->issuetype);
        self::assertNull($fields->status);
        self::assertNull($fields->assignee);
    }

    public function testJiraIssueFieldsFromApiResponseWithSubtasks(): void
    {
        $subtask1 = new stdClass();
        $subtask1->key = 'SUB-1';

        $subtask2 = new stdClass();
        $subtask2->key = 'SUB-2';

        $response = new stdClass();
        $response->subtasks = [$subtask1, 'invalid-subtask', $subtask2];

        $fields = JiraIssueFields::fromApiResponse($response);

        self::assertCount(2, $fields->subtasks);
        self::assertSame('SUB-1', $fields->subtasks[0]->key);
        self::assertSame('SUB-2', $fields->subtasks[1]->key);
    }

    public function testJiraIssueFieldsIsEpicCaseInsensitive(): void
    {
        $issuetype = new JiraIssueType(name: 'EPIC');
        $fields = new JiraIssueFields(issuetype: $issuetype);

        self::assertTrue($fields->isEpic());
    }

    public function testJiraIssueFieldsIsEpicReturnsFalseForNullName(): void
    {
        $issuetype = new JiraIssueType(name: null);
        $fields = new JiraIssueFields(issuetype: $issuetype);

        self::assertFalse($fields->isEpic());
    }

    public function testJiraIssueFieldsGetSubtaskKeysFiltersNulls(): void
    {
        $subtask1 = new JiraSubtask(key: 'KEY-1');
        $subtask2 = new JiraSubtask(key: null);
        $subtask3 = new JiraSubtask(key: 'KEY-3');

        $fields = new JiraIssueFields(subtasks: [$subtask1, $subtask2, $subtask3]);

        self::assertSame(['KEY-1', 'KEY-3'], $fields->getSubtaskKeys());
    }

    // ==================== JiraIssueType tests ====================

    public function testJiraIssueTypeConstructorDefaults(): void
    {
        $type = new JiraIssueType();

        self::assertNull($type->id);
        self::assertNull($type->name);
        self::assertNull($type->self);
        self::assertNull($type->description);
        self::assertFalse($type->subtask);
    }

    public function testJiraIssueTypeFromApiResponseWithFullData(): void
    {
        $response = new stdClass();
        $response->id = 10001;
        $response->name = 'Task';
        $response->self = 'https://jira.example.com/rest/api/2/issuetype/10001';
        $response->description = 'A task type';
        $response->subtask = true;

        $type = JiraIssueType::fromApiResponse($response);

        self::assertSame(10001, $type->id);
        self::assertSame('Task', $type->name);
        self::assertSame('https://jira.example.com/rest/api/2/issuetype/10001', $type->self);
        self::assertSame('A task type', $type->description);
        self::assertTrue($type->subtask);
    }

    public function testJiraIssueTypeFromApiResponseSubtaskFalseWhenNotBoolean(): void
    {
        $response = new stdClass();
        $response->subtask = 'true'; // String, not boolean

        $type = JiraIssueType::fromApiResponse($response);

        self::assertFalse($type->subtask);
    }

    // ==================== JiraStatus tests ====================

    public function testJiraStatusConstructorDefaults(): void
    {
        $status = new JiraStatus();

        self::assertNull($status->id);
        self::assertNull($status->name);
        self::assertNull($status->self);
        self::assertNull($status->description);
    }

    public function testJiraStatusFromApiResponseWithFullData(): void
    {
        $response = new stdClass();
        $response->id = 1;
        $response->name = 'In Progress';
        $response->self = 'https://jira.example.com/rest/api/2/status/1';
        $response->description = 'Work is in progress';

        $status = JiraStatus::fromApiResponse($response);

        self::assertSame(1, $status->id);
        self::assertSame('In Progress', $status->name);
        self::assertSame('https://jira.example.com/rest/api/2/status/1', $status->self);
        self::assertSame('Work is in progress', $status->description);
    }

    // ==================== JiraAssignee tests ====================

    public function testJiraAssigneeConstructorDefaults(): void
    {
        $assignee = new JiraAssignee();

        self::assertNull($assignee->accountId);
        self::assertNull($assignee->displayName);
        self::assertNull($assignee->emailAddress);
        self::assertNull($assignee->self);
    }

    public function testJiraAssigneeFromApiResponseWithFullData(): void
    {
        $response = new stdClass();
        $response->accountId = '5b109f2e9729b51b54dc274d';
        $response->displayName = 'Jane Smith';
        $response->emailAddress = 'jane@example.com';
        $response->self = 'https://jira.example.com/rest/api/2/user?accountId=5b109f2e9729b51b54dc274d';

        $assignee = JiraAssignee::fromApiResponse($response);

        self::assertSame('5b109f2e9729b51b54dc274d', $assignee->accountId);
        self::assertSame('Jane Smith', $assignee->displayName);
        self::assertSame('jane@example.com', $assignee->emailAddress);
        self::assertSame('https://jira.example.com/rest/api/2/user?accountId=5b109f2e9729b51b54dc274d', $assignee->self);
    }

    public function testJiraAssigneeFromApiResponseIgnoresNonStringValues(): void
    {
        $response = new stdClass();
        $response->accountId = 12345; // Integer, not string
        $response->displayName = ['array', 'value']; // Array, not string

        $assignee = JiraAssignee::fromApiResponse($response);

        self::assertNull($assignee->accountId);
        self::assertNull($assignee->displayName);
    }

    // ==================== JiraSubtask tests ====================

    public function testJiraSubtaskConstructorDefaults(): void
    {
        $subtask = new JiraSubtask();

        self::assertNull($subtask->id);
        self::assertNull($subtask->key);
        self::assertNull($subtask->self);
        self::assertNull($subtask->fields);
    }

    public function testJiraSubtaskFromApiResponseWithFullData(): void
    {
        $fields = new stdClass();
        $fields->summary = 'Subtask Summary';

        $response = new stdClass();
        $response->id = 999;
        $response->key = 'PROJ-50';
        $response->self = 'https://jira.example.com/rest/api/2/issue/999';
        $response->fields = $fields;

        $subtask = JiraSubtask::fromApiResponse($response);

        self::assertSame(999, $subtask->id);
        self::assertSame('PROJ-50', $subtask->key);
        self::assertSame('https://jira.example.com/rest/api/2/issue/999', $subtask->self);
        self::assertInstanceOf(JiraIssueFields::class, $subtask->fields);
        self::assertSame('Subtask Summary', $subtask->fields->summary);
    }

    public function testJiraSubtaskFromApiResponseWithMinimalData(): void
    {
        $response = new stdClass();

        $subtask = JiraSubtask::fromApiResponse($response);

        self::assertNull($subtask->id);
        self::assertNull($subtask->key);
        self::assertNull($subtask->self);
        self::assertNull($subtask->fields);
    }
}

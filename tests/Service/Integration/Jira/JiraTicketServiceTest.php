<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration\Jira;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Exception\Integration\Jira\JiraApiException;
use App\Service\Integration\Jira\JiraHttpClientService;
use App\Service\Integration\Jira\JiraTicketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(JiraTicketService::class)]
final class JiraTicketServiceTest extends TestCase
{
    private JiraHttpClientService&MockObject $httpClient;
    private JiraTicketService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(JiraHttpClientService::class);
        $this->service = new JiraTicketService($this->httpClient);
    }

    private function createEntry(
        ?Project $project = null,
        ?Customer $customer = null,
        ?Activity $activity = null,
        string $description = '',
    ): Entry {
        $entry = new Entry();

        if (null !== $project) {
            $entry->setProject($project);
        }
        if (null !== $customer) {
            $entry->setCustomer($customer);
        }
        if (null !== $activity) {
            $entry->setActivity($activity);
        }
        if ('' !== $description) {
            $entry->setDescription($description);
        }

        return $entry;
    }

    private function createProject(string $name = 'Test Project', ?string $jiraId = 'TEST'): Project
    {
        $project = new Project();
        $project->setName($name);
        $project->setJiraId($jiraId);

        return $project;
    }

    private function createCustomer(string $name = 'Test Customer'): Customer
    {
        $customer = new Customer();
        $customer->setName($name);

        return $customer;
    }

    private function createActivity(string $name = 'Development'): Activity
    {
        $activity = new Activity();
        $activity->setName($name);

        return $activity;
    }

    #[Test]
    public function createTicketSucceeds(): void
    {
        $project = $this->createProject('My Project', 'PROJ');
        $customer = $this->createCustomer('ACME Corp');
        $activity = $this->createActivity('Feature Development');
        $entry = $this->createEntry($project, $customer, $activity, 'Working on feature X');

        $response = new stdClass();
        $response->key = 'PROJ-123';
        $response->id = '12345';

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('issue', $this->callback(static fn (array $data) => 'PROJ' === $data['fields']['project']['key']
                    && str_contains($data['fields']['summary'], 'ACME Corp')
                    && 'Working on feature X' === $data['fields']['description']
                    && 'Story' === $data['fields']['issuetype']['name']))
            ->willReturn($response);

        $result = $this->service->createTicket($entry);

        $this->assertSame('PROJ-123', $result->key);
    }

    #[Test]
    public function createTicketThrowsWhenNoProject(): void
    {
        $entry = $this->createEntry();

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Entry has no project');

        $this->service->createTicket($entry);
    }

    #[Test]
    public function createTicketThrowsWhenNoJiraId(): void
    {
        $project = $this->createProject('My Project', null);
        $entry = $this->createEntry($project);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Project has no Jira ID configured');

        $this->service->createTicket($entry);
    }

    #[Test]
    public function createTicketThrowsWhenEmptyJiraId(): void
    {
        $project = $this->createProject('My Project', '');
        $entry = $this->createEntry($project);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Project has no Jira ID configured');

        $this->service->createTicket($entry);
    }

    #[Test]
    public function createTicketUsesDefaultDescriptionWhenEmpty(): void
    {
        $project = $this->createProject();
        $entry = $this->createEntry($project);

        $response = new stdClass();
        $response->key = 'TEST-1';

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('issue', $this->callback(
                static fn (array $data) => 'No description provided' === $data['fields']['description'],
            ))
            ->willReturn($response);

        $this->service->createTicket($entry);
    }

    #[Test]
    public function createTicketThrowsOnInvalidResponse(): void
    {
        $project = $this->createProject();
        $entry = $this->createEntry($project);

        $this->httpClient->method('post')->willReturn(new stdClass());

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Failed to create Jira ticket');

        $this->service->createTicket($entry);
    }

    #[Test]
    public function createTicketThrowsOnNonObjectResponse(): void
    {
        $project = $this->createProject();
        $entry = $this->createEntry($project);

        $this->httpClient->method('post')->willReturn(['key' => 'TEST-1']);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Failed to create Jira ticket');

        $this->service->createTicket($entry);
    }

    #[Test]
    #[DataProvider('provideIssueTypes')]
    public function createTicketDeterminesIssueTypeFromActivity(string $activityName, string $expectedIssueType): void
    {
        $project = $this->createProject();
        $activity = $this->createActivity($activityName);
        $entry = $this->createEntry($project, null, $activity);

        $response = new stdClass();
        $response->key = 'TEST-1';

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('issue', $this->callback(
                static fn (array $data) => $expectedIssueType === $data['fields']['issuetype']['name'],
            ))
            ->willReturn($response);

        $this->service->createTicket($entry);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideIssueTypes(): array
    {
        return [
            'bug activity' => ['Bug Fixing', 'Bug'],
            'fix activity' => ['Quick Fix', 'Bug'],
            'feature activity' => ['Feature Development', 'Story'],
            'development activity' => ['Development', 'Story'],
            'support activity' => ['Customer Support', 'Task'],
            'maintenance activity' => ['System Maintenance', 'Task'],
            'generic activity' => ['Meeting', 'Task'],
        ];
    }

    #[Test]
    public function searchTicketsPostsJqlQuery(): void
    {
        $response = new stdClass();
        $response->issues = [];
        $response->total = 0;

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('search', [
                'jql' => 'project = TEST AND status = Open',
                'maxResults' => 10,
            ])
            ->willReturn($response);

        $result = $this->service->searchTickets('project = TEST AND status = Open', [], 10);

        $this->assertSame(0, $result->total);
    }

    #[Test]
    public function searchTicketsIncludesFields(): void
    {
        $response = new stdClass();
        $response->issues = [];

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('search', [
                'jql' => 'project = TEST',
                'maxResults' => 1,
                'fields' => ['summary', 'status', 'assignee'],
            ])
            ->willReturn($response);

        $this->service->searchTickets('project = TEST', ['summary', 'status', 'assignee']);
    }

    #[Test]
    public function doesTicketExistReturnsTrueWhenTicketExists(): void
    {
        $response = new stdClass();
        $response->key = 'TEST-123';

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('issue/TEST-123')
            ->willReturn($response);

        $result = $this->service->doesTicketExist('TEST-123');

        $this->assertTrue($result);
    }

    #[Test]
    public function doesTicketExistReturnsFalseWhenTicketDoesNotExist(): void
    {
        $this->httpClient->method('get')
            ->willThrowException(new JiraApiException('Not found', 404));

        $result = $this->service->doesTicketExist('INVALID-999');

        $this->assertFalse($result);
    }

    #[Test]
    public function doesTicketExistReturnsFalseForEmptyKey(): void
    {
        $this->httpClient->expects($this->never())->method('get');

        $result = $this->service->doesTicketExist('');

        $this->assertFalse($result);
    }

    #[Test]
    public function getTicketReturnsTicketDetails(): void
    {
        $response = new stdClass();
        $response->key = 'TEST-123';
        $response->fields = new stdClass();
        $response->fields->summary = 'Test ticket';

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('issue/TEST-123')
            ->willReturn($response);

        $result = $this->service->getTicket('TEST-123');

        $this->assertSame('TEST-123', $result->key);
    }

    #[Test]
    public function getTicketWithFieldsAppendsQueryString(): void
    {
        $response = new stdClass();

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('issue/TEST-123?fields=summary,status')
            ->willReturn($response);

        $this->service->getTicket('TEST-123', ['summary', 'status']);
    }

    #[Test]
    public function getTicketThrowsOnEmptyKey(): void
    {
        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Ticket key cannot be empty');

        $this->service->getTicket('');
    }

    #[Test]
    public function updateTicketSendsPutRequest(): void
    {
        $response = new stdClass();

        $this->httpClient->expects($this->once())
            ->method('put')
            ->with('issue/TEST-123', ['fields' => ['summary' => 'Updated summary']])
            ->willReturn($response);

        $this->service->updateTicket('TEST-123', ['fields' => ['summary' => 'Updated summary']]);
    }

    #[Test]
    public function updateTicketThrowsOnEmptyKey(): void
    {
        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Ticket key cannot be empty');

        $this->service->updateTicket('', ['fields' => []]);
    }

    #[Test]
    public function addCommentPostsComment(): void
    {
        $response = new stdClass();
        $response->id = '10001';

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('issue/TEST-123/comment', ['body' => 'This is a comment'])
            ->willReturn($response);

        $result = $this->service->addComment('TEST-123', 'This is a comment');

        $this->assertSame('10001', $result->id);
    }

    #[Test]
    public function addCommentThrowsOnEmptyTicketKey(): void
    {
        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Ticket key cannot be empty');

        $this->service->addComment('', 'Comment');
    }

    #[Test]
    public function addCommentThrowsOnEmptyComment(): void
    {
        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Comment cannot be empty');

        $this->service->addComment('TEST-123', '');
    }

    #[Test]
    public function getTransitionsReturnsTransitionsList(): void
    {
        $response = new stdClass();
        $transition1 = new stdClass();
        $transition1->id = '21';
        $transition1->name = 'In Progress';
        $transition1->to = new stdClass();
        $transition1->to->id = '3';
        $transition1->to->name = 'In Progress';

        $transition2 = new stdClass();
        $transition2->id = '31';
        $transition2->name = 'Done';
        $transition2->to = new stdClass();
        $transition2->to->id = '4';
        $transition2->to->name = 'Done';

        $response->transitions = [$transition1, $transition2];

        $this->httpClient->method('get')
            ->with('issue/TEST-123/transitions')
            ->willReturn($response);

        $result = $this->service->getTransitions('TEST-123');

        $this->assertCount(2, $result);
        $this->assertSame('21', $result[0]['id']);
        $this->assertSame('In Progress', $result[0]['name']);
        $this->assertSame('3', $result[0]['to']['id']);
    }

    #[Test]
    public function getTransitionsReturnsEmptyArrayForEmptyKey(): void
    {
        $this->httpClient->expects($this->never())->method('get');

        $result = $this->service->getTransitions('');

        $this->assertSame([], $result);
    }

    #[Test]
    public function getTransitionsReturnsEmptyArrayOnError(): void
    {
        $this->httpClient->method('get')
            ->willThrowException(new JiraApiException('Error'));

        $result = $this->service->getTransitions('TEST-123');

        $this->assertSame([], $result);
    }

    #[Test]
    public function getTransitionsReturnsEmptyArrayForNonObjectResponse(): void
    {
        $this->httpClient->method('get')->willReturn(['transitions' => []]);

        $result = $this->service->getTransitions('TEST-123');

        $this->assertSame([], $result);
    }

    #[Test]
    public function getTransitionsReturnsEmptyArrayWhenNoTransitions(): void
    {
        $response = new stdClass();
        // No transitions property

        $this->httpClient->method('get')->willReturn($response);

        $result = $this->service->getTransitions('TEST-123');

        $this->assertSame([], $result);
    }

    #[Test]
    public function transitionTicketPostsTransition(): void
    {
        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('issue/TEST-123/transitions', [
                'transition' => ['id' => '21'],
            ]);

        $this->service->transitionTicket('TEST-123', '21');
    }

    #[Test]
    public function transitionTicketIncludesFields(): void
    {
        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('issue/TEST-123/transitions', [
                'transition' => ['id' => '21'],
                'fields' => ['resolution' => ['name' => 'Fixed']],
            ]);

        $this->service->transitionTicket('TEST-123', '21', ['resolution' => ['name' => 'Fixed']]);
    }

    #[Test]
    public function transitionTicketThrowsOnEmptyTicketKey(): void
    {
        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Ticket key cannot be empty');

        $this->service->transitionTicket('', '21');
    }

    #[Test]
    public function transitionTicketThrowsOnEmptyTransitionId(): void
    {
        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Transition ID cannot be empty');

        $this->service->transitionTicket('TEST-123', '');
    }

    #[Test]
    public function getSubticketsReturnsSubtasksList(): void
    {
        $response = new stdClass();
        $response->key = 'TEST-123';
        $response->fields = new stdClass();
        $response->fields->subtasks = [];

        $subtask = new stdClass();
        $subtask->key = 'TEST-124';
        $subtask->fields = new stdClass();
        $subtask->fields->summary = 'Subtask 1';
        $subtask->fields->status = new stdClass();
        $subtask->fields->status->name = 'Open';
        $subtask->fields->assignee = new stdClass();
        $subtask->fields->assignee->displayName = 'John Doe';

        $response->fields->subtasks = [$subtask];

        $this->httpClient->method('get')
            ->with('issue/TEST-123')
            ->willReturn($response);

        $result = $this->service->getSubtickets('TEST-123');

        $this->assertCount(1, $result);
        $this->assertSame('TEST-124', $result[0]['key']);
        $this->assertSame('Subtask 1', $result[0]['summary']);
        $this->assertSame('Open', $result[0]['status']);
        $this->assertSame('John Doe', $result[0]['assignee']);
    }

    #[Test]
    public function getSubticketsReturnsEmptyArrayForEmptyKey(): void
    {
        $this->httpClient->expects($this->never())->method('get');

        $result = $this->service->getSubtickets('');

        $this->assertSame([], $result);
    }

    #[Test]
    public function getSubticketsReturnsEmptyArrayForNonObjectResponse(): void
    {
        $this->httpClient->method('get')->willReturn(['key' => 'TEST-123']);

        $result = $this->service->getSubtickets('TEST-123');

        $this->assertSame([], $result);
    }

    #[Test]
    public function getSubticketsThrowsOnApiError(): void
    {
        $this->httpClient->method('get')
            ->willThrowException(new JiraApiException('Network error', 500));

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessageMatches('/Failed to get subtasks for ticket TEST-123/');

        $this->service->getSubtickets('TEST-123');
    }

    #[Test]
    public function createTicketGeneratesSummaryFromParts(): void
    {
        $project = $this->createProject('ProjectX');
        $customer = $this->createCustomer('CustomerY');
        $activity = $this->createActivity('TaskZ');
        $entry = $this->createEntry($project, $customer, $activity);

        $response = new stdClass();
        $response->key = 'TEST-1';

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('issue', $this->callback(static function (array $data) {
                $summary = $data['fields']['summary'];

                return str_contains($summary, 'CustomerY')
                    && str_contains($summary, 'ProjectX')
                    && str_contains($summary, 'TaskZ');
            }))
            ->willReturn($response);

        $this->service->createTicket($entry);
    }

    #[Test]
    public function createTicketBuildsPartialSummaryWhenSomePartsEmpty(): void
    {
        // Project with name but no customer/activity
        $project = $this->createProject('MyProject', 'TEST');
        $entry = $this->createEntry($project);

        $response = new stdClass();
        $response->key = 'TEST-1';

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('issue', $this->callback(
                static fn (array $data) => 'MyProject' === $data['fields']['summary'],
            ))
            ->willReturn($response);

        $this->service->createTicket($entry);
    }

    #[Test]
    public function getTransitionsSkipsNonObjectTransitions(): void
    {
        $response = new stdClass();
        $validTransition = new stdClass();
        $validTransition->id = '21';
        $validTransition->name = 'Valid';
        $validTransition->to = new stdClass();
        $validTransition->to->id = '3';
        $validTransition->to->name = 'Done';

        $response->transitions = [
            $validTransition,
            'invalid_string', // Should be skipped
            ['invalid' => 'array'], // Should be skipped
        ];

        $this->httpClient->method('get')->willReturn($response);

        $result = $this->service->getTransitions('TEST-123');

        $this->assertCount(1, $result);
        $this->assertSame('21', $result[0]['id']);
    }

    #[Test]
    public function getSubticketsReturnsEmptyArrayWhenFieldsNotPresent(): void
    {
        $response = new stdClass();
        $response->key = 'TEST-123';
        // No fields property

        $this->httpClient->method('get')->willReturn($response);

        $result = $this->service->getSubtickets('TEST-123');

        $this->assertSame([], $result);
    }

    #[Test]
    public function getSubticketsHandlesSubtaskWithNullAssignee(): void
    {
        $response = new stdClass();
        $response->key = 'TEST-123';
        $response->fields = new stdClass();

        $subtask = new stdClass();
        $subtask->key = 'TEST-124';
        $subtask->fields = new stdClass();
        $subtask->fields->summary = 'Unassigned subtask';
        $subtask->fields->status = new stdClass();
        $subtask->fields->status->name = 'Open';
        $subtask->fields->assignee = null;

        $response->fields->subtasks = [$subtask];

        $this->httpClient->method('get')->willReturn($response);

        $result = $this->service->getSubtickets('TEST-123');

        $this->assertNull($result[0]['assignee']);
    }
}

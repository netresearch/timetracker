<?php

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\Entry;
use App\Exception\Integration\Jira\JiraApiException;

use function is_object;
use function sprintf;

/**
 * Manages Jira ticket operations.
 * Handles ticket creation, search, and validation.
 */
class JiraTicketService
{
    public function __construct(
        private readonly JiraHttpClientService $jiraHttpClientService,
    ) {
    }

    /**
     * Creates a new Jira ticket from entry.
     *
     * @throws JiraApiException
     */
    public function createTicket(Entry $entry): mixed
    {
        $project = $entry->getProject();

        if (!$project instanceof \App\Entity\Project) {
            throw new JiraApiException('Entry has no project', 400);
        }

        $projectJiraId = $project->getJiraId();

        if ($projectJiraId === null || $projectJiraId === '') {
            throw new JiraApiException('Project has no Jira ID configured', 400);
        }

        $description = '' !== $entry->getDescription() ? $entry->getDescription() : 'No description provided';

        $ticketData = [
            'fields' => [
                'project' => [
                    'key' => $projectJiraId,
                ],
                'summary' => $this->generateTicketSummary($entry),
                'description' => $description,
                'issuetype' => [
                    'name' => $this->getIssueType($entry),
                ],
            ],
        ];

        // Add custom fields if configured
        $customFields = $this->getCustomFields();
        if ([] !== $customFields) {
            $ticketData['fields'] = array_merge($ticketData['fields'], $customFields);
        }

        $response = $this->jiraHttpClientService->post('issue', $ticketData);

        if (!is_object($response) || !property_exists($response, 'key')) {
            throw new JiraApiException('Failed to create Jira ticket', 500);
        }

        return $response;
    }

    /**
     * Searches for Jira tickets using JQL.
     *
     * @param string        $jql    JQL query string
     * @param array<string> $fields Fields to return
     * @param int           $limit  Maximum number of results
     *
     * @throws JiraApiException
     */
    public function searchTickets(string $jql, array $fields = [], int $limit = 1): mixed
    {
        $searchData = [
            'jql' => $jql,
            'maxResults' => $limit,
        ];

        if ([] !== $fields) {
            $searchData['fields'] = $fields;
        }

        return $this->jiraHttpClientService->post('search', $searchData);
    }

    /**
     * Checks if a ticket exists in Jira.
     */
    public function doesTicketExist(string $ticketKey): bool
    {
        if ('' === $ticketKey) {
            return false;
        }

        try {
            $this->jiraHttpClientService->get(sprintf('issue/%s', $ticketKey));

            return true;
        } catch (JiraApiException) {
            return false;
        }
    }

    /**
     * Gets subtasks for a Jira ticket.
     *
     * @throws JiraApiException
     *
     * @return list<array{key: mixed, summary: mixed, status: mixed, assignee: mixed}>
     */
    public function getSubtickets(string $ticketKey): array
    {
        if ('' === $ticketKey) {
            return [];
        }

        try {
            $issue = $this->jiraHttpClientService->get(sprintf('issue/%s', $ticketKey));

            if (!is_object($issue) || !property_exists($issue, 'fields')) {
                return [];
            }

            if (!is_object($issue->fields) || !property_exists($issue->fields, 'subtasks')) {
                return [];
            }

            $subtasks = [];

            if (is_array($issue->fields->subtasks)) {
                foreach ($issue->fields->subtasks as $subtask) {
                if (!is_object($subtask)) {
                    continue;
                }

                $subtasks[] = [
                    'key' => property_exists($subtask, 'key') ? $subtask->key : '',
                    'summary' => (is_object($subtask->fields ?? null) && property_exists($subtask->fields, 'summary')) ? $subtask->fields->summary : '',
                    'status' => (is_object($subtask->fields ?? null) && property_exists($subtask->fields, 'status') && is_object($subtask->fields->status) && property_exists($subtask->fields->status, 'name')) ? $subtask->fields->status->name : '',
                    'assignee' => (is_object($subtask->fields ?? null) && property_exists($subtask->fields, 'assignee') && is_object($subtask->fields->assignee) && property_exists($subtask->fields->assignee, 'displayName')) ? $subtask->fields->assignee->displayName : null,
                ];
                }
            }

            return $subtasks;
        } catch (JiraApiException $jiraApiException) {
            throw new JiraApiException(sprintf('Failed to get subtasks for ticket %s: %s', $ticketKey, $jiraApiException->getMessage()), $jiraApiException->getCode(), null, $jiraApiException);
        }
    }

    /**
     * Gets ticket details from Jira.
     *
     * @param array<string> $fields
     *
     * @throws JiraApiException
     */
    public function getTicket(string $ticketKey, array $fields = []): mixed
    {
        if ('' === $ticketKey) {
            throw new JiraApiException('Ticket key cannot be empty', 400);
        }

        $url = sprintf('issue/%s', $ticketKey);

        if ([] !== $fields) {
            $url .= '?fields=' . implode(',', $fields);
        }

        return $this->jiraHttpClientService->get($url);
    }

    /**
     * Updates a Jira ticket.
     *
     * @param array<string, mixed> $updateData
     *
     * @throws JiraApiException
     */
    public function updateTicket(string $ticketKey, array $updateData): mixed
    {
        if ('' === $ticketKey) {
            throw new JiraApiException('Ticket key cannot be empty', 400);
        }

        return $this->jiraHttpClientService->put(sprintf('issue/%s', $ticketKey), $updateData);
    }

    /**
     * Adds a comment to a Jira ticket.
     *
     * @throws JiraApiException
     */
    public function addComment(string $ticketKey, string $comment): mixed
    {
        if ('' === $ticketKey) {
            throw new JiraApiException('Ticket key cannot be empty', 400);
        }

        if ('' === $comment) {
            throw new JiraApiException('Comment cannot be empty', 400);
        }

        return $this->jiraHttpClientService->post(
            sprintf('issue/%s/comment', $ticketKey),
            ['body' => $comment],
        );
    }

    /**
     * Gets transitions available for a ticket.
     *
     * @return array<int, array{id: string, name: string, to: array{id: string, name: string}}>
     */
    public function getTransitions(string $ticketKey): array
    {
        if ('' === $ticketKey) {
            return [];
        }

        try {
            $response = $this->jiraHttpClientService->get(sprintf('issue/%s/transitions', $ticketKey));

            if (!is_object($response) || !property_exists($response, 'transitions')) {
                return [];
            }

            $transitions = [];

            if (is_array($response->transitions)) {
                foreach ($response->transitions as $transition) {
                if (!is_object($transition)) {
                    continue;
                }

                $to = $transition->to ?? null;
                $transitions[] = [
                    'id' => property_exists($transition, 'id') && is_scalar($transition->id ?? '') ? (string) ($transition->id ?? '') : '',
                    'name' => property_exists($transition, 'name') && is_scalar($transition->name ?? '') ? (string) ($transition->name ?? '') : '',
                    'to' => [
                        'id' => (is_object($to) && property_exists($to, 'id') && is_scalar($to->id ?? '')) ? (string) ($to->id ?? '') : '',
                        'name' => (is_object($to) && property_exists($to, 'name') && is_scalar($to->name ?? '')) ? (string) ($to->name ?? '') : '',
                    ],
                ];
                }
            }

            return $transitions;
        } catch (JiraApiException) {
            return [];
        }
    }

    /**
     * Transitions a ticket to a new status.
     *
     * @param array<string, mixed> $fields
     *
     * @throws JiraApiException
     */
    public function transitionTicket(string $ticketKey, string $transitionId, array $fields = []): void
    {
        if ('' === $ticketKey) {
            throw new JiraApiException('Ticket key cannot be empty', 400);
        }

        if ('' === $transitionId) {
            throw new JiraApiException('Transition ID cannot be empty', 400);
        }

        $transitionData = [
            'transition' => [
                'id' => $transitionId,
            ],
        ];

        if ([] !== $fields) {
            $transitionData['fields'] = $fields;
        }

        $this->jiraHttpClientService->post(sprintf('issue/%s/transitions', $ticketKey), $transitionData);
    }

    /**
     * Generates ticket summary from entry.
     */
    private function generateTicketSummary(Entry $entry): string
    {
        $parts = [];

        $customer = $entry->getCustomer();
        if ($customer instanceof \App\Entity\Customer) {
            $parts[] = $customer->getName();
        }

        $project = $entry->getProject();
        if ($project instanceof \App\Entity\Project) {
            $parts[] = $project->getName();
        }

        $activity = $entry->getActivity();
        if ($activity instanceof \App\Entity\Activity) {
            $parts[] = $activity->getName();
        }

        if ([] !== $parts) {
            return implode(' - ', $parts);
        }

        return 'Timetracker Entry';
    }

    /**
     * Determines issue type for entry.
     */
    private function getIssueType(Entry $entry): string
    {
        // This could be configurable per project or activity
        $activity = $entry->getActivity();

        if ($activity instanceof \App\Entity\Activity) {
            $activityName = strtolower($activity->getName());

            if (str_contains($activityName, 'bug') || str_contains($activityName, 'fix')) {
                return 'Bug';
            }

            if (str_contains($activityName, 'feature') || str_contains($activityName, 'development')) {
                return 'Story';
            }

            if (str_contains($activityName, 'support') || str_contains($activityName, 'maintenance')) {
                return 'Task';
            }
        }

        return 'Task'; // Default issue type
    }

    /**
     * Gets custom fields for ticket creation.
     *
     * @return array<string, mixed>
     */
    private function getCustomFields(): array
    {
        return [];
        // Add any custom field mappings here
        // Example:
        // if ($entry->getPriority()) {
        //     $customFields['customfield_10001'] = $entry->getPriority();
        // }
    }
}

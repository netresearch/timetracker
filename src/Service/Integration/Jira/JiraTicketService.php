<?php

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\Entry;
use App\Exception\Integration\Jira\JiraApiException;

use function sprintf;

/**
 * Manages Jira ticket operations.
 * Handles ticket creation, search, and validation.
 */
class JiraTicketService
{
    public function __construct(
        private readonly JiraHttpClientService $httpClient,
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

        if (!$project) {
            throw new JiraApiException('Entry has no project', 400);
        }

        $projectJiraId = $project->getJiraId();

        if (!$projectJiraId) {
            throw new JiraApiException('Project has no Jira ID configured', 400);
        }

        $description = $entry->getDescription() ?: 'No description provided';

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
        $customFields = $this->getCustomFields($entry);
        if (!empty($customFields)) {
            $ticketData['fields'] = array_merge($ticketData['fields'], $customFields);
        }

        $response = $this->httpClient->post('issue', $ticketData);

        if (!isset($response->key)) {
            throw new JiraApiException('Failed to create Jira ticket', 500);
        }

        return $response;
    }

    /**
     * Searches for Jira tickets using JQL.
     *
     * @param string $jql    JQL query string
     * @param array<string> $fields Fields to return
     * @param int    $limit  Maximum number of results
     *
     * @throws JiraApiException
     */
    public function searchTickets(string $jql, array $fields = [], int $limit = 1): mixed
    {
        $searchData = [
            'jql' => $jql,
            'maxResults' => $limit,
        ];

        if (!empty($fields)) {
            $searchData['fields'] = $fields;
        }

        return $this->httpClient->post('search', $searchData);
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
            $this->httpClient->get(sprintf('issue/%s', $ticketKey));

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
     * @return array<int, array{key: string, summary: string, status: string, assignee: string|null}>
     */
    public function getSubtickets(string $ticketKey): array
    {
        if ('' === $ticketKey) {
            return [];
        }

        try {
            $issue = $this->httpClient->get(sprintf('issue/%s', $ticketKey));

            if (!is_object($issue) || !property_exists($issue, 'fields') || !property_exists($issue->fields, 'subtasks')) {
                return [];
            }

            $subtasks = [];

            foreach ($issue->fields->subtasks as $subtask) {
                $subtasks[] = [
                    'key' => $subtask->key ?? '',
                    'summary' => $subtask->fields->summary ?? '',
                    'status' => $subtask->fields->status->name ?? '',
                    'assignee' => $subtask->fields->assignee->displayName ?? null,
                ];
            }

            return $subtasks;
        } catch (JiraApiException $e) {
            throw new JiraApiException(sprintf('Failed to get subtasks for ticket %s: %s', $ticketKey, $e->getMessage()), $e->getCode(), null);
        }
    }

    /**
     * Gets ticket details from Jira.
     *
     * @param array<string> $fields
     * @throws JiraApiException
     */
    public function getTicket(string $ticketKey, array $fields = []): mixed
    {
        if ('' === $ticketKey) {
            throw new JiraApiException('Ticket key cannot be empty', 400);
        }

        $url = sprintf('issue/%s', $ticketKey);

        if (!empty($fields)) {
            $url .= '?fields=' . implode(',', $fields);
        }

        return $this->httpClient->get($url);
    }

    /**
     * Updates a Jira ticket.
     *
     * @param array<string, mixed> $updateData
     * @throws JiraApiException
     */
    public function updateTicket(string $ticketKey, array $updateData): mixed
    {
        if ('' === $ticketKey) {
            throw new JiraApiException('Ticket key cannot be empty', 400);
        }

        return $this->httpClient->put(sprintf('issue/%s', $ticketKey), $updateData);
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

        return $this->httpClient->post(
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
            $response = $this->httpClient->get(sprintf('issue/%s/transitions', $ticketKey));

            if (!is_object($response) || !property_exists($response, 'transitions')) {
                return [];
            }

            $transitions = [];

            foreach ($response->transitions as $transition) {
                if (!is_object($transition)) {
                    continue;
                }
                $to = $transition->to ?? null;
                $transitions[] = [
                    'id' => property_exists($transition, 'id') ? (string) ($transition->id ?? '') : '',
                    'name' => property_exists($transition, 'name') ? (string) ($transition->name ?? '') : '',
                    'to' => [
                        'id' => (is_object($to) && property_exists($to, 'id')) ? (string) ($to->id ?? '') : '',
                        'name' => (is_object($to) && property_exists($to, 'name')) ? (string) ($to->name ?? '') : '',
                    ],
                ];
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

        if (!empty($fields)) {
            $transitionData['fields'] = $fields;
        }

        $this->httpClient->post(sprintf('issue/%s/transitions', $ticketKey), $transitionData);
    }

    /**
     * Generates ticket summary from entry.
     */
    private function generateTicketSummary(Entry $entry): string
    {
        $parts = [];

        $customer = $entry->getCustomer();
        if ($customer) {
            $parts[] = $customer->getName();
        }

        $project = $entry->getProject();
        if ($project) {
            $parts[] = $project->getName();
        }

        $activity = $entry->getActivity();
        if ($activity) {
            $parts[] = $activity->getName();
        }

        if (!empty($parts)) {
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

        if ($activity) {
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
    private function getCustomFields(Entry $entry): array
    {
        return [];

        // Add any custom field mappings here
        // Example:
        // if ($entry->getPriority()) {
        //     $customFields['customfield_10001'] = $entry->getPriority();
        // }
    }
}

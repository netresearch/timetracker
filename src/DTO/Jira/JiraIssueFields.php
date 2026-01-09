<?php

declare(strict_types=1);

namespace App\DTO\Jira;

/**
 * Data Transfer Object for Jira Issue Fields.
 *
 * Represents the fields object within a Jira issue response.
 */
final readonly class JiraIssueFields
{
    /**
     * @param list<JiraSubtask> $subtasks
     */
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public ?JiraIssueType $issuetype = null,
        public ?JiraStatus $status = null,
        public ?JiraAssignee $assignee = null,
        public array $subtasks = [],
    ) {
    }

    /**
     * Create from stdClass object returned by Jira API.
     *
     * @param object $response The API response object
     */
    public static function fromApiResponse(object $response): self
    {
        /** @var array<string, mixed> $data */
        $data = (array) $response;

        $issuetype = null;
        if (isset($data['issuetype']) && is_object($data['issuetype'])) {
            $issuetype = JiraIssueType::fromApiResponse($data['issuetype']);
        }

        $status = null;
        if (isset($data['status']) && is_object($data['status'])) {
            $status = JiraStatus::fromApiResponse($data['status']);
        }

        $assignee = null;
        if (isset($data['assignee']) && is_object($data['assignee'])) {
            $assignee = JiraAssignee::fromApiResponse($data['assignee']);
        }

        $subtasks = [];
        if (isset($data['subtasks']) && is_iterable($data['subtasks'])) {
            foreach ($data['subtasks'] as $subtask) {
                if (is_object($subtask)) {
                    $subtasks[] = JiraSubtask::fromApiResponse($subtask);
                }
            }
        }

        return new self(
            summary: isset($data['summary']) && is_string($data['summary']) ? $data['summary'] : null,
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            issuetype: $issuetype,
            status: $status,
            assignee: $assignee,
            subtasks: $subtasks,
        );
    }

    /**
     * Check if this issue type is an Epic.
     */
    public function isEpic(): bool
    {
        return $this->issuetype !== null
            && $this->issuetype->name !== null
            && strtolower($this->issuetype->name) === 'epic';
    }

    /**
     * Get all subtask keys.
     *
     * @return list<string>
     */
    public function getSubtaskKeys(): array
    {
        $keys = [];
        foreach ($this->subtasks as $subtask) {
            if ($subtask->key !== null) {
                $keys[] = $subtask->key;
            }
        }

        return $keys;
    }
}

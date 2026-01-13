<?php

declare(strict_types=1);

namespace App\DTO\Jira;

use function is_object;
use function is_scalar;
use function is_string;

/**
 * Data Transfer Object for Jira Issue responses.
 *
 * Represents an issue (ticket) from the Jira API.
 */
final readonly class JiraIssue
{
    /**
     * @param list<string> $subtaskKeys
     */
    public function __construct(
        public ?int $id = null,
        public ?string $key = null,
        public ?string $self = null,
        public ?JiraIssueFields $fields = null,
        public array $subtaskKeys = [],
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

        $fields = null;
        if (isset($data['fields']) && is_object($data['fields'])) {
            $fields = JiraIssueFields::fromApiResponse($data['fields']);
        }

        $subtaskKeys = [];
        if (null !== $fields) {
            $subtaskKeys = $fields->getSubtaskKeys();
        }

        return new self(
            id: isset($data['id']) && is_scalar($data['id']) ? (int) $data['id'] : null,
            key: isset($data['key']) && is_string($data['key']) ? $data['key'] : null,
            self: isset($data['self']) && is_string($data['self']) ? $data['self'] : null,
            fields: $fields,
            subtaskKeys: $subtaskKeys,
        );
    }

    /**
     * Check if this issue is an Epic type.
     */
    public function isEpic(): bool
    {
        return null !== $this->fields && $this->fields->isEpic();
    }

    /**
     * Get the issue summary/title.
     */
    public function getSummary(): ?string
    {
        return $this->fields?->summary;
    }
}

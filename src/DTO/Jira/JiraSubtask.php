<?php

declare(strict_types=1);

namespace App\DTO\Jira;

/**
 * Data Transfer Object for Jira Subtask.
 */
final readonly class JiraSubtask
{
    public function __construct(
        public ?int $id = null,
        public ?string $key = null,
        public ?string $self = null,
        public ?JiraIssueFields $fields = null,
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

        return new self(
            id: isset($data['id']) && is_scalar($data['id']) ? (int) $data['id'] : null,
            key: isset($data['key']) && is_string($data['key']) ? $data['key'] : null,
            self: isset($data['self']) && is_string($data['self']) ? $data['self'] : null,
            fields: $fields,
        );
    }
}

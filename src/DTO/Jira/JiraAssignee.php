<?php

declare(strict_types=1);

namespace App\DTO\Jira;

/**
 * Data Transfer Object for Jira Assignee.
 */
final readonly class JiraAssignee
{
    public function __construct(
        public ?string $accountId = null,
        public ?string $displayName = null,
        public ?string $emailAddress = null,
        public ?string $self = null,
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

        return new self(
            accountId: isset($data['accountId']) && is_string($data['accountId']) ? $data['accountId'] : null,
            displayName: isset($data['displayName']) && is_string($data['displayName']) ? $data['displayName'] : null,
            emailAddress: isset($data['emailAddress']) && is_string($data['emailAddress']) ? $data['emailAddress'] : null,
            self: isset($data['self']) && is_string($data['self']) ? $data['self'] : null,
        );
    }
}

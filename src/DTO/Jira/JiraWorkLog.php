<?php

declare(strict_types=1);

namespace App\DTO\Jira;

use function is_object;
use function is_scalar;
use function is_string;

/**
 * Data Transfer Object for Jira Work Log responses.
 *
 * Represents a work log entry from the Jira API.
 */
final readonly class JiraWorkLog
{
    public function __construct(
        public ?int $id = null,
        public ?string $self = null,
        public ?string $comment = null,
        public ?string $started = null,
        public ?int $timeSpentSeconds = null,
        public ?string $updated = null,
        public ?string $authorAccountId = null,
        public ?string $authorName = null,
        public ?string $authorEmail = null,
        public ?string $issueId = null,
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

        /** @var array<string, mixed> $author */
        $author = isset($data['author']) && is_object($data['author']) ? (array) $data['author'] : [];

        return new self(
            id: isset($data['id']) && is_scalar($data['id']) ? (int) $data['id'] : null,
            self: isset($data['self']) && is_string($data['self']) ? $data['self'] : null,
            comment: isset($data['comment']) && is_string($data['comment']) ? $data['comment'] : null,
            started: isset($data['started']) && is_string($data['started']) ? $data['started'] : null,
            timeSpentSeconds: isset($data['timeSpentSeconds']) && is_scalar($data['timeSpentSeconds']) ? (int) $data['timeSpentSeconds'] : null,
            updated: isset($data['updated']) && is_string($data['updated']) ? $data['updated'] : null,
            authorAccountId: isset($author['accountId']) && is_string($author['accountId']) ? $author['accountId'] : null,
            authorName: isset($author['name']) && is_string($author['name']) ? $author['name'] : null,
            authorEmail: isset($author['emailAddress']) && is_string($author['emailAddress']) ? $author['emailAddress'] : null,
            issueId: isset($data['issueId']) && is_scalar($data['issueId']) ? (string) $data['issueId'] : null,
        );
    }

    /**
     * Check if the work log has a valid ID.
     */
    public function hasValidId(): bool
    {
        return null !== $this->id && $this->id > 0;
    }
}

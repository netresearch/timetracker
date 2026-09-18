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
            id: self::intOrNull($data, 'id'),
            self: self::stringOrNull($data, 'self'),
            comment: self::stringOrNull($data, 'comment'),
            started: self::stringOrNull($data, 'started'),
            timeSpentSeconds: self::intOrNull($data, 'timeSpentSeconds'),
            updated: self::stringOrNull($data, 'updated'),
            authorAccountId: self::stringOrNull($author, 'accountId'),
            authorName: self::stringOrNull($author, 'name'),
            authorEmail: self::stringOrNull($author, 'emailAddress'),
            issueId: self::scalarAsStringOrNull($data, 'issueId'),
        );
    }

    /**
     * A key Jira sends as a JSON string, taken only when it really is one.
     *
     * @param array<string, mixed> $data
     */
    private static function stringOrNull(array $data, string $key): ?string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
    }

    /**
     * A numeric key. Jira sends ids as either a number or a string, so any
     * scalar is accepted and cast.
     *
     * @param array<string, mixed> $data
     */
    private static function intOrNull(array $data, string $key): ?int
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? (int) $data[$key] : null;
    }

    /**
     * Like intOrNull, but kept as a string — issueId is an identifier, not a
     * number to do arithmetic on.
     *
     * @param array<string, mixed> $data
     */
    private static function scalarAsStringOrNull(array $data, string $key): ?string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : null;
    }

    /**
     * Check if the work log has a valid ID.
     */
    public function hasValidId(): bool
    {
        return null !== $this->id && $this->id > 0;
    }
}

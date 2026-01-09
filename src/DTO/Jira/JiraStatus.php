<?php

declare(strict_types=1);

namespace App\DTO\Jira;

/**
 * Data Transfer Object for Jira Status.
 */
final readonly class JiraStatus
{
    public function __construct(
        public ?int $id = null,
        public ?string $name = null,
        public ?string $self = null,
        public ?string $description = null,
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
            id: isset($data['id']) && is_scalar($data['id']) ? (int) $data['id'] : null,
            name: isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
            self: isset($data['self']) && is_string($data['self']) ? $data['self'] : null,
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
        );
    }
}

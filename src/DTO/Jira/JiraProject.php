<?php

declare(strict_types=1);

namespace App\DTO\Jira;

/**
 * Data Transfer Object for Jira Project.
 */
final readonly class JiraProject
{
    public function __construct(
        public ?int $id = null,
        public ?string $key = null,
        public ?string $name = null,
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
            id: isset($data['id']) && is_scalar($data['id']) ? (int) $data['id'] : null,
            key: isset($data['key']) && is_string($data['key']) ? $data['key'] : null,
            name: isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
            self: isset($data['self']) && is_string($data['self']) ? $data['self'] : null,
        );
    }

    /**
     * Convert to array format.
     *
     * @return array{key: string, name: string, id: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key ?? '',
            'name' => $this->name ?? '',
            'id' => $this->id !== null ? (string) $this->id : '',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\DTO\Jira;

/**
 * Data Transfer Object for Jira Transition.
 */
final readonly class JiraTransition
{
    public function __construct(
        public ?int $id = null,
        public ?string $name = null,
        public ?JiraStatus $to = null,
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

        $to = null;
        if (isset($data['to']) && is_object($data['to'])) {
            $to = JiraStatus::fromApiResponse($data['to']);
        }

        return new self(
            id: isset($data['id']) && is_scalar($data['id']) ? (int) $data['id'] : null,
            name: isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
            to: $to,
        );
    }
}

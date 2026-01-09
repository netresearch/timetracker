<?php

declare(strict_types=1);

namespace App\DTO\Jira;

/**
 * Data Transfer Object for Jira Search/JQL Results.
 */
final readonly class JiraSearchResult
{
    /**
     * @param list<JiraIssue> $issues
     */
    public function __construct(
        public int $startAt = 0,
        public int $maxResults = 0,
        public int $total = 0,
        public array $issues = [],
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

        $issues = [];
        if (isset($data['issues']) && is_iterable($data['issues'])) {
            foreach ($data['issues'] as $issue) {
                if (is_object($issue)) {
                    $issues[] = JiraIssue::fromApiResponse($issue);
                }
            }
        }

        return new self(
            startAt: isset($data['startAt']) && is_int($data['startAt']) ? $data['startAt'] : 0,
            maxResults: isset($data['maxResults']) && is_int($data['maxResults']) ? $data['maxResults'] : 0,
            total: isset($data['total']) && is_int($data['total']) ? $data['total'] : 0,
            issues: $issues,
        );
    }

    /**
     * Get all issue keys from the search result.
     *
     * @return list<string>
     */
    public function getIssueKeys(): array
    {
        $keys = [];
        foreach ($this->issues as $issue) {
            if ($issue->key !== null) {
                $keys[] = $issue->key;
            }
        }

        return $keys;
    }
}

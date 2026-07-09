<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Jira;

use function is_string;
use function strcasecmp;

/**
 * The Jira account behind a token (GET /rest/api/2/myself) — used to filter worklogs by author.
 */
final readonly class JiraUserIdentity
{
    public function __construct(
        public ?string $accountId = null,
        public ?string $name = null,
        public ?string $email = null,
    ) {
    }

    /**
     * Create from stdClass object returned by the Jira API.
     *
     * @param object $response The API response object
     */
    public static function fromApiResponse(object $response): self
    {
        /** @var array<string, mixed> $data */
        $data = (array) $response;

        return new self(
            accountId: isset($data['accountId']) && is_string($data['accountId']) ? $data['accountId'] : null,
            name: isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
            email: isset($data['emailAddress']) && is_string($data['emailAddress']) ? $data['emailAddress'] : null,
        );
    }

    /**
     * Whether the given worklog was authored by this identity.
     */
    public function matchesWorklogAuthor(JiraWorkLog $workLog): bool
    {
        if (null !== $this->accountId && null !== $workLog->authorAccountId) {
            return $this->accountId === $workLog->authorAccountId;
        }

        if (null !== $this->name && null !== $workLog->authorName) {
            return 0 === strcasecmp($this->name, $workLog->authorName);
        }

        if (null !== $this->email && null !== $workLog->authorEmail) {
            return 0 === strcasecmp($this->email, $workLog->authorEmail);
        }

        return false;
    }
}

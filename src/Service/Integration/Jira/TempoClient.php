<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\DTO\Tempo\TempoAccount;
use App\DTO\Tempo\TempoAccountLink;
use App\Exception\Integration\Jira\JiraApiException;

use function is_array;
use function is_object;
use function sprintf;

/**
 * Read-only Tempo Timesheets client (ADR-026 §3). Delegates its signed GETs to
 * {@see JiraOAuthApiService::getFromTenant()}, reusing the existing Jira OAuth1
 * token to reach /rest/tempo-accounts/… on the same tenant — no new credential.
 *
 * Kept minimal (accounts + links) on purpose (YAGNI); it exists to feed the
 * customer derivation, not to expose Tempo generally.
 */
final readonly class TempoClient
{
    public function __construct(
        private JiraOAuthApiService $api,
    ) {
    }

    /**
     * The Tempo Accounts usable in a project.
     *
     * @return list<TempoAccount>
     */
    public function accountsForProject(int $projectId): array
    {
        $decoded = $this->getOrEmptyOnForbidden(sprintf('/rest/tempo-accounts/1/account/project/%d', $projectId));

        return $this->mapList($decoded, TempoAccount::fromApiResponse(...));
    }

    /**
     * The account-to-project links, carrying the `defaultAccount` flag.
     *
     * @return list<TempoAccountLink>
     */
    public function linksForProject(int $projectId): array
    {
        $decoded = $this->getOrEmptyOnForbidden(sprintf('/rest/tempo-accounts/1/link/project/%d', $projectId));

        return $this->mapList($decoded, TempoAccountLink::fromApiResponse(...));
    }

    /**
     * Signed tenant GET that treats a 403 as "no Tempo visibility here" and
     * returns an empty body, so the derivation degrades to the Jira category /
     * keyword fallback rather than aborting (ADR-026 §3 + verification §1: try
     * under the token, fall back on a 403). Any other failure propagates and is
     * caught per-key by {@see ProjectImportProposalService}.
     *
     * @throws JiraApiException on a non-403 failure
     */
    private function getOrEmptyOnForbidden(string $absolutePath): mixed
    {
        try {
            return $this->api->getFromTenant($absolutePath);
        } catch (JiraApiException $jiraApiException) {
            if (403 === $jiraApiException->getCode()) {
                return [];
            }

            throw $jiraApiException;
        }
    }

    /**
     * Map a decoded JSON array through a per-item factory, dropping items the
     * factory rejects (null) and anything that is not an object.
     *
     * @template T of object
     *
     * @param callable(object): (T|null) $factory
     *
     * @return list<T>
     */
    private function mapList(mixed $decoded, callable $factory): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        $mapped = [];
        foreach ($decoded as $item) {
            if (!is_object($item)) {
                continue;
            }

            $dto = $factory($item);
            if (null !== $dto) {
                $mapped[] = $dto;
            }
        }

        return $mapped;
    }
}

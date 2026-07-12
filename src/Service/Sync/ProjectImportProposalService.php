<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\DTO\Sync\ProjectImportProposal;
use App\DTO\Tempo\TempoAccount;
use App\DTO\Tempo\TempoAccountLink;
use App\DTO\Tempo\TempoCustomerRef;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Integration\Jira\TempoClient;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function sprintf;

/**
 * Derives a Customer + Project proposal for each unresolved Jira project key
 * (ADR-026 P1a). Read-only: it queries Jira (project + category) and Tempo
 * (accounts + links) and returns proposals; nothing is persisted here.
 *
 * Precedence (ADR-026 §1–2):
 *  1. exactly one distinct Tempo Account customer      -> tempo
 *  2. several customers but a single default link      -> tempo-default
 *  3. several customers, no single default             -> ambiguous (park)
 *  4. no Tempo customer, project category present      -> category
 *  5. otherwise                                        -> none
 * A key that is not a Jira project yields `not-a-project`.
 */
final readonly class ProjectImportProposalService
{
    public function __construct(
        private JiraOAuthApiFactory $apiFactory,
    ) {
    }

    /**
     * @param list<string> $jiraKeys
     *
     * @return list<ProjectImportProposal>
     */
    public function proposeForKeys(array $jiraKeys, TicketSystem $ticketSystem, User $tokenOwner): array
    {
        $api = $this->apiFactory->create($tokenOwner, $ticketSystem);
        $tempo = new TempoClient($api);

        $proposals = [];
        foreach ($jiraKeys as $jiraKey) {
            $proposals[] = $this->propose($jiraKey, $api, $tempo);
        }

        return $proposals;
    }

    private function propose(string $jiraKey, JiraOAuthApiService $api, TempoClient $tempo): ProjectImportProposal
    {
        $info = $api->getProjectInfo($jiraKey);
        if (null === $info) {
            return new ProjectImportProposal($jiraKey, null, null, $jiraKey, null, null, ProjectImportProposal::SOURCE_NOT_A_PROJECT);
        }

        $projectId = $info['id'];
        $projectName = $info['name'];
        $categoryName = $info['categoryName'];

        $accounts = $tempo->accountsForProject($projectId);

        /** @var array<string, TempoCustomerRef> $customersByKey */
        $customersByKey = [];
        foreach ($accounts as $account) {
            if ($account->customer instanceof TempoCustomerRef) {
                $customersByKey[$account->customer->key] = $account->customer;
            }
        }

        $distinctCount = count($customersByKey);

        if (1 === $distinctCount) {
            $customer = array_first($customersByKey);

            return new ProjectImportProposal($jiraKey, $projectId, $projectName, $jiraKey, $customer->name, $customer->key, ProjectImportProposal::SOURCE_TEMPO);
        }

        if ($distinctCount > 1) {
            $default = $this->resolveByDefaultLink($tempo, $projectId, $accounts);
            if ($default instanceof TempoCustomerRef) {
                return new ProjectImportProposal($jiraKey, $projectId, $projectName, $jiraKey, $default->name, $default->key, ProjectImportProposal::SOURCE_TEMPO_DEFAULT);
            }

            $candidates = array_map(
                static fn (TempoCustomerRef $customer): string => sprintf('%s [%s]', $customer->name, $customer->key),
                array_values($customersByKey),
            );

            return new ProjectImportProposal($jiraKey, $projectId, $projectName, $jiraKey, null, null, ProjectImportProposal::SOURCE_AMBIGUOUS, $candidates);
        }

        if (null !== $categoryName && '' !== $categoryName) {
            return new ProjectImportProposal($jiraKey, $projectId, $projectName, $jiraKey, $categoryName, null, ProjectImportProposal::SOURCE_CATEGORY);
        }

        return new ProjectImportProposal($jiraKey, $projectId, $projectName, $jiraKey, null, null, ProjectImportProposal::SOURCE_NONE);
    }

    /**
     * When several customers compete, a single `defaultAccount: true` link
     * resolves to its account's customer (ADR-026 §2). Null when there is not
     * exactly one default, or that account carries no customer.
     *
     * @param list<TempoAccount> $accounts
     */
    private function resolveByDefaultLink(TempoClient $tempo, int $projectId, array $accounts): ?TempoCustomerRef
    {
        $links = $tempo->linksForProject($projectId);
        $defaults = array_values(array_filter(
            $links,
            static fn (TempoAccountLink $link): bool => $link->defaultAccount,
        ));

        if (1 !== count($defaults)) {
            return null;
        }

        $defaultAccountId = $defaults[0]->accountId;
        foreach ($accounts as $account) {
            if ($account->id === $defaultAccountId && $account->customer instanceof TempoCustomerRef) {
                return $account->customer;
            }
        }

        return null;
    }
}

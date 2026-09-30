<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Security\TokenEncryptionService;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use SensitiveParameter;
use Symfony\Component\Routing\RouterInterface;

/**
 * JiraOAuthApiService whose HTTP client is a given (failing) stub, with public entry points
 * to the three request paths that map Guzzle failures.
 */
final class FailingClientJiraOAuthApiService extends JiraOAuthApiService
{
    public function __construct(
        User $user,
        TicketSystem $ticketSystem,
        ManagerRegistry $managerRegistry,
        RouterInterface $router,
        TokenEncryptionService $tokenEncryptionService,
        private readonly Client $client,
    ) {
        parent::__construct($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService);
    }

    public function requestObject(string $url): object
    {
        return $this->getResponse('GET', $url);
    }

    /**
     * @return list<object>
     */
    public function requestList(string $url): array
    {
        return $this->getResponseArray($url);
    }

    protected function getClient(string $tokenMode = 'user', #[SensitiveParameter] ?string $oAuthToken = null): Client
    {
        return $this->client;
    }
}

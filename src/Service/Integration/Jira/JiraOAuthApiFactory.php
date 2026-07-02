<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\DeploymentType;
use App\Service\ClockInterface;
use App\Service\Security\TokenEncryptionService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Service\Attribute\Required;

class JiraOAuthApiFactory
{
    public ManagerRegistry $managerRegistry;

    public RouterInterface $router;

    public TokenEncryptionService $tokenEncryptionService;

    public ClockInterface $clock;

    #[Required]
    public function setDependencies(ManagerRegistry $managerRegistry, RouterInterface $router, TokenEncryptionService $tokenEncryptionService, ClockInterface $clock): void
    {
        $this->managerRegistry = $managerRegistry;
        $this->router = $router;
        $this->tokenEncryptionService = $tokenEncryptionService;
        $this->clock = $clock;
    }

    /**
     * Builds the API service matching the ticket system's deployment type:
     * OAuth 2.0 (3LO) against the Atlassian gateway for CLOUD, the classic
     * OAuth 1.0a application-link flow for SERVER / Data Center.
     */
    public function create(User $user, TicketSystem $ticketSystem): JiraOAuthApiService
    {
        if (DeploymentType::CLOUD === $ticketSystem->getDeploymentType()) {
            return new JiraCloudApiService($user, $ticketSystem, $this->managerRegistry, $this->router, $this->tokenEncryptionService, $this->clock);
        }

        return new JiraOAuthApiService($user, $ticketSystem, $this->managerRegistry, $this->router, $this->tokenEncryptionService);
    }
}

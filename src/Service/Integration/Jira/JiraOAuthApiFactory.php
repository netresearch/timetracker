<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Security\TokenEncryptionService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Service\Attribute\Required;

class JiraOAuthApiFactory
{
    public ManagerRegistry $managerRegistry;

    public RouterInterface $router;

    public TokenEncryptionService $tokenEncryptionService;

    #[Required]
    public function setDependencies(ManagerRegistry $managerRegistry, RouterInterface $router, TokenEncryptionService $tokenEncryptionService): void
    {
        $this->managerRegistry = $managerRegistry;
        $this->router = $router;
        $this->tokenEncryptionService = $tokenEncryptionService;
    }

    public function create(User $user, TicketSystem $ticketSystem): JiraOAuthApiService
    {
        return new JiraOAuthApiService($user, $ticketSystem, $this->managerRegistry, $this->router, $this->tokenEncryptionService);
    }
}

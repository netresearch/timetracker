<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Enum\TicketSystemType;
use App\Security\ApiToken\RequireScope;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The Jira ticket systems the caller books worklogs on, each with whether a
 * token is stored for the caller and the URL that starts the (re)authorization
 * (#911) — the Settings "connect Jira" list.
 *
 * Listed from the ticket systems, not from the caller's users_ticket_systems
 * rows: a user who never connected, or whose connection is switched off, has
 * no usable row and is exactly who needs the action. `connected` only says a
 * token is stored, not that Jira still accepts it, so reconnecting stays
 * offered either way.
 */
final readonly class ListJiraConnectionsAction
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[RequireScope('sync:read')]
    #[Route(path: '/api/v2/jira/connections', name: 'api_v2_jira_connections', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        /** @var list<TicketSystem> $ticketSystems */
        $ticketSystems = $this->managerRegistry->getRepository(TicketSystem::class)->findBy(
            ['type' => TicketSystemType::JIRA->value, 'bookTime' => true],
            ['name' => 'ASC'],
        );

        $connections = [];
        foreach ($ticketSystems as $ticketSystem) {
            $connections[] = [
                'ticket_system_id' => $ticketSystem->getId(),
                'ticket_system_name' => $ticketSystem->getName(),
                'connected' => $this->hasStoredToken($user, $ticketSystem),
                'connect_url' => $this->urlGenerator->generate('jiraConnect', ['ticketSystem' => $ticketSystem->getId()]),
            ];
        }

        return new JsonResponse(['connections' => $connections]);
    }

    private function hasStoredToken(User $user, TicketSystem $ticketSystem): bool
    {
        foreach ($user->getUserTicketsystems() as $userTicketsystem) {
            if (!$userTicketsystem instanceof UserTicketsystem
                || $userTicketsystem->getTicketSystem()?->getId() !== $ticketSystem->getId()) {
                continue;
            }

            return !$userTicketsystem->getAvoidConnection() && '' !== $userTicketsystem->getAccessToken();
        }

        return false;
    }
}

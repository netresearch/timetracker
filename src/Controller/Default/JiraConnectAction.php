<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\TicketSystemType;
use App\Exception\Integration\Jira\JiraApiException;
use App\Model\Response;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Starts the Jira authorization for the current user ("connect Jira", #911):
 * fetches a fresh authorize URL and redirects the browser there. Jira sends the
 * user back to the OAuth callback, which stores the new access token.
 *
 * A plain GET the SPA links to, so the browser follows the redirect itself
 * (no popup to block) and a fresh request token is minted on each click.
 */
final class JiraConnectAction extends BaseController
{
    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    #[Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraOAuthApiFactory): void
    {
        $this->jiraOAuthApiFactory = $jiraOAuthApiFactory;
    }

    #[Route(path: '/jira/connect/{ticketSystem}', name: 'jiraConnect', requirements: ['ticketSystem' => '\d+'], methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(int $ticketSystem, #[CurrentUser] ?User $user = null): RedirectResponse|Response
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $system = $this->managerRegistry->getRepository(TicketSystem::class)->find($ticketSystem);
        if (!$system instanceof TicketSystem || TicketSystemType::JIRA !== $system->getType()) {
            return new Response('Ticket system not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        try {
            return new RedirectResponse($this->jiraOAuthApiFactory->create($user, $system)->getAuthorizationUrl());
        } catch (JiraApiException $jiraApiException) {
            return new Response($jiraApiException->getMessage(), \Symfony\Component\HttpFoundation\Response::HTTP_BAD_GATEWAY);
        }
    }
}

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
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

use function htmlspecialchars;
use function sprintf;

/**
 * Starts the Jira authorization for the current user ("connect Jira", #911):
 * fetches a fresh authorize URL and redirects the browser there. Jira sends the
 * user back to the OAuth callback, which stores the new access token.
 *
 * A plain GET the SPA links to, so the browser follows the redirect itself
 * (no popup to block) and a fresh request token is minted on each click. This
 * is the only place that issues a request token for a redirect: it is kept in
 * the session, and the OAuth 1.0a callback accepts no other token.
 */
final class JiraConnectAction extends BaseController
{
    /** Session key prefix (+ ticket system id) of the request token the callback must receive. */
    public const string PENDING_REQUEST_TOKEN_KEY = 'jira_oauth_request_token_';

    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    private LoggerInterface $logger;

    #[Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraOAuthApiFactory): void
    {
        $this->jiraOAuthApiFactory = $jiraOAuthApiFactory;
    }

    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    #[Route(path: '/jira/connect/{ticketSystem}', name: 'jiraConnect', requirements: ['ticketSystem' => '\d+'], methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, int $ticketSystem, #[CurrentUser] ?User $user = null): RedirectResponse|Response
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $system = $this->managerRegistry->getRepository(TicketSystem::class)->find($ticketSystem);
        if (!$system instanceof TicketSystem || TicketSystemType::JIRA !== $system->getType()) {
            return new Response('Ticket system not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        try {
            $authorization = $this->jiraOAuthApiFactory->create($user, $system)->createAuthorizationRequest();
        } catch (JiraApiException $jiraApiException) {
            // The message can carry hosts, ports and Jira's answer: log it, show a short notice.
            $this->logger->error('Jira connect: could not start the authorization', [
                'ticketSystem' => $system->getId(),
                'exception' => $jiraApiException,
            ]);

            return $this->connectFailedResponse($system);
        }

        if (null !== $authorization->requestToken) {
            $request->getSession()->set(self::PENDING_REQUEST_TOKEN_KEY . $system->getId(), $authorization->requestToken);
        }

        return new RedirectResponse($authorization->url);
    }

    private function connectFailedResponse(TicketSystem $ticketSystem): Response
    {
        $response = new Response(sprintf(
            '<!DOCTYPE html><html lang="%s"><head><meta charset="utf-8"><title>%s</title></head><body><p>%s</p><p><a href="%s">%s</a></p></body></html>',
            htmlspecialchars($this->translator->getLocale()),
            htmlspecialchars($this->translate('Jira connection')),
            htmlspecialchars($this->translate('%ticketSystem% could not be reached to start the authorization. Please try again later.', ['%ticketSystem%' => $ticketSystem->getName()])),
            htmlspecialchars($this->generateUrl('ui_spa', ['path' => 'settings/sync'])),
            htmlspecialchars($this->translate('Back to Settings')),
        ), \Symfony\Component\HttpFoundation\Response::HTTP_BAD_GATEWAY);
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        return $response;
    }
}

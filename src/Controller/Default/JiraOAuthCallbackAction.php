<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Model\Response;
use App\Service\ClockInterface;
use App\Service\Integration\Jira\CloudOAuthStateCodec;
use App\Service\Integration\Jira\JiraCloudApiService;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Security\TokenEncryptionService;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

use function is_string;
use function sprintf;

final class JiraOAuthCallbackAction extends BaseController
{
    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    private TokenEncryptionService $tokenEncryptionService;

    private ClockInterface $clock;

    #[Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraOAuthApiFactory): void
    {
        $this->jiraOAuthApiFactory = $jiraOAuthApiFactory;
    }

    #[Required]
    public function setCloudStateDependencies(TokenEncryptionService $tokenEncryptionService, ClockInterface $clock): void
    {
        $this->tokenEncryptionService = $tokenEncryptionService;
        $this->clock = $clock;
    }

    /**
     * Handles both callback flavours: the OAuth 1.0a application-link flow
     * (`?tsid=…&oauth_token=…&oauth_verifier=…`, Jira Server/DC) and the
     * OAuth 2.0 3LO flow (`?code=…&state=…`, Jira Cloud — the ticket system
     * id rides encrypted inside `state` because Cloud redirect URIs must
     * match the registered URL exactly).
     *
     * @throws Exception           When database operations fail
     * @throws BadRequestException When query parameters are invalid
     * @throws JiraApiException    When Jira API operations fail
     */
    #[Route(path: '/jiraoauthcallback', name: 'jiraOAuthCallback', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): RedirectResponse|Response
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $state = $request->query->get('state');
        if (is_string($state) && '' !== $state) {
            return $this->handleCloudCallback($request, $user, $state);
        }

        return $this->handleServerCallback($request, $user);
    }

    private function handleCloudCallback(Request $request, User $user, string $state): RedirectResponse|Response
    {
        try {
            $codec = new CloudOAuthStateCodec($this->tokenEncryptionService, $this->clock);
            $decoded = $codec->decode($state);

            if ($decoded['userId'] !== (int) $user->getId()) {
                return new Response('OAuth state does not belong to the current user', \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);
            }

            $ticketSystem = $this->managerRegistry->getRepository(TicketSystem::class)->find($decoded['ticketSystemId']);
            if (!$ticketSystem instanceof TicketSystem) {
                return new Response('Ticket system not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            $error = $request->query->get('error');
            if (is_string($error) && '' !== $error) {
                return new Response(sprintf('Jira authorization was not granted: %s', $error));
            }

            $code = $request->query->get('code');
            if (!is_string($code) || '' === $code) {
                return new Response('Invalid OAuth callback parameters', \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
            }

            $jiraApi = $this->jiraOAuthApiFactory->create($user, $ticketSystem);
            if (!$jiraApi instanceof JiraCloudApiService) {
                return new Response('Ticket system is not configured as Jira Cloud', \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
            }

            $jiraApi->exchangeAuthorizationCode($code);
            $jiraApi->updateEntriesJiraWorkLogsLimited(1);

            return $this->redirectToRoute('_start');
        } catch (JiraApiException $jiraApiException) {
            return new Response($jiraApiException->getMessage());
        }
    }

    private function handleServerCallback(Request $request, User $user): RedirectResponse|Response
    {
        /** @var TicketSystem $ticketSystem */
        $ticketSystem = $this->managerRegistry->getRepository(TicketSystem::class)->find($request->query->get('tsid'));
        if (!$ticketSystem instanceof TicketSystem) {
            return new Response('Ticket system not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        try {
            $jiraOAuthApi = $this->jiraOAuthApiFactory->create($user, $ticketSystem);
            $oauthToken = $request->query->get('oauth_token');
            $oauthVerifier = $request->query->get('oauth_verifier');
            if (!is_string($oauthToken) || '' === $oauthToken || !is_string($oauthVerifier) || '' === $oauthVerifier) {
                return new Response('Invalid OAuth callback parameters', \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
            }

            $jiraOAuthApi->fetchOAuthAccessToken($oauthToken, $oauthVerifier);
            $jiraOAuthApi->updateEntriesJiraWorkLogsLimited(1);

            return $this->redirectToRoute('_start');
        } catch (JiraApiException $jiraApiException) {
            return new Response($jiraApiException->getMessage());
        }
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Service\Attribute\Required;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function is_string;

class SecurityController extends AbstractController
{
    private TokenStorageInterface $tokenStorage;

    private RequestStack $requestStack;
    private AuthenticationUtils $authenticationUtils;

    #[Required]
    public function setTokenStorage(TokenStorageInterface $tokenStorage, AuthenticationUtils $authenticationUtils): void
    {
        $this->tokenStorage = $tokenStorage;
        $this->authenticationUtils = $authenticationUtils;
    }

    #[Required]
    public function setRequestStack(RequestStack $requestStack): void
    {
        $this->requestStack = $requestStack;
    }

    /**
     * Unified login action handling both GET (form display) and POST (authentication).
     * The actual authentication is handled by LdapAuthenticator on POST requests.
     *
     * @throws LoaderError  When template loading fails
     * @throws RuntimeError When template rendering fails
     * @throws SyntaxError  When template syntax is invalid
     */
    public function login(): Response
    {
        // Only a full-fledged login has nothing to do here — send it to the
        // start page. A session resumed from the REMEMBERME cookie must fall
        // through to the form: the security entry point sends it here to step
        // up when an IS_AUTHENTICATED_FULLY endpoint denies it (#587), and
        // bouncing it away would make every such endpoint a dead end.
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute('_start');
        }

        // Render login form with error handling
        // Works for both GET (initial display) and POST (after failed authentication)
        $logoUrl = $this->getParameter('app_logo_url');
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        return $this->render('login.html.twig', [
            'locale' => $locale,
            'apptitle' => 'Netresearch TimeTracker',
            'logo_url' => is_string($logoUrl) ? $logoUrl : '',
            'last_username' => $this->authenticationUtils->getLastUsername(),
            'error' => $this->authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * This method can be empty - it will be intercepted by the logout key on your firewall.
     *
     * @codeCoverageIgnore
     */
    public function logout(): Response
    {
        // In production, this method is intercepted by the firewall's logout.
        // In tests (firewall disabled), perform a manual logout and redirect.
        $this->tokenStorage->setToken(null);
        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request && $request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return new RedirectResponse('/login');
    }
}

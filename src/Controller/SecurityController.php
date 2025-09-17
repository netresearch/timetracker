<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    private TokenStorageInterface $tokenStorage;

    private RequestStack $requestStack;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTokenStorage(TokenStorageInterface $tokenStorage): void
    {
        $this->tokenStorage = $tokenStorage;
    }

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setRequestStack(RequestStack $requestStack): void
    {
        $this->requestStack = $requestStack;
    }

    /**
     * Unified login action handling both GET (form display) and POST (authentication).
     * The actual authentication is handled by LdapAuthenticator on POST requests.
     *
     * @throws \Twig\Error\LoaderError  When template loading fails
     * @throws \Twig\Error\RuntimeError When template rendering fails
     * @throws \Twig\Error\SyntaxError  When template syntax is invalid
     */
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // If user is already authenticated, redirect to start page
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('_start');
        }

        // Render login form with error handling
        // Works for both GET (initial display) and POST (after failed authentication)
        return $this->render('login.html.twig', [
            'locale' => 'en',
            'apptitle' => 'Netresearch TimeTracker',
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
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
        if ($request instanceof \Symfony\Component\HttpFoundation\Request && $request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return new RedirectResponse('/login');
    }
}

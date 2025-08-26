<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
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
     * This is just a route target for the login form
     * The actual rendering is now handled by Symfony's form_login system.
     */
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Render login form with error handling
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
        if (null !== $request && $request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return new RedirectResponse('/login');
    }
}

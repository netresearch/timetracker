<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    /**
     * This is just a route target for the login form
     * The actual rendering is now handled by Symfony's form_login system
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
     * This method can be empty - it will be intercepted by the logout key on your firewall
     */
    public function logout(): never
    {
        // Symfony's security system handles the logout process
        throw new \LogicException('This method should never be reached!');
    }
}

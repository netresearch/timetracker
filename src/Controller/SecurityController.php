<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Force a direct response to avoid redirect loops
        $response = new Response();
        $content = $this->renderView('login.html.twig', [
            'locale' => 'en',
            'apptitle' => $this->getParameter('app_title'),
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
        $response->setContent($content);
        return $response;
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

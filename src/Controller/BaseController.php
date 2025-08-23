<?php

/**
 * basic controller to share some features with the child controllers
 *
 * PHP version 5
 *
 * @category  Controller
 * @package   App\Controller
 * @author    Mathias Lieber <mathias.lieber@netresearch.de>
 * @copyright 2012 Netresearch App Factory AG
 * @license   No license
 * @link      http://www.netresearch.de
 */

namespace App\Controller;

use App\Entity\User;
use App\Service\Util\LocalizationService;
use App\Helper\LoginHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * BaseController.php
 *
 * @category Controller
 * @package  App\Controller
 * @author   Mathias Lieber <mathias.lieber@netresearch.de>
 * @license  No license
 * @link     http://www.netresearch.de
 */
class BaseController extends AbstractController
{
    public ManagerRegistry $managerRegistry;

    /** @var ParameterBagInterface */
    protected $params;

    /** @var TranslatorInterface */
    protected $translator;

    /** @var KernelInterface */
    protected $kernel;

    /** @var ManagerRegistry */
    protected $doctrineRegistry;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setCoreDependencies(
        ManagerRegistry $managerRegistry,
        ParameterBagInterface $params,
        TranslatorInterface $translator,
        KernelInterface $kernel
    ): void {
        $this->managerRegistry = $managerRegistry;
        $this->doctrineRegistry = $managerRegistry; // BC for legacy usages
        $this->params = $params;
        $this->translator = $translator;
        $this->kernel = $kernel;
    }

    /**
     * Check if user is logged in via Symfony Security
     *
     *
     * @return bool
     */
    protected function isLoggedIn(Request $request)
    {
        // Require a fully authenticated session (no remember-me)
        return $this->isGranted('IS_AUTHENTICATED_FULLY');
    }

    /**
     * Returns the user id
     *
     *
     * @return int User ID
     * @throw AccessDeniedException
     */
    protected function getUserId(Request $request): int
    {
        if (!$this->isLoggedIn($request)) {
            throw new AccessDeniedException('No user logged in');
        }

        // Get user from Symfony security context
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw new AccessDeniedException('No user logged in');
        }

        // Handle impersonation through Symfony's built-in functionality
        return (int) $user->getId();
    }

    /**
     * Redirects to the login page
     *
     *
     */
    protected function login(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response
    {
        if (!$request->isXmlHttpRequest()
            && !$this->isJsonRequest($request)
        ) {
            return $this->redirectToRoute('_login');
        }

        return new Response($this->generateUrl('_login'), \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);
    }

    /**
     * checks the user type to be PL
     *
     *
     * @return bool
     */
    protected function isPl(Request $request): bool
    {
        if (false === $this->checkLogin($request)) {
            return false;
        }

        $userId = $this->getUserId($request);
        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(\App\Entity\User::class);
        $user = $objectRepository->find($userId);
        return $user instanceof User && $user->getType() === 'PL';
    }


    /**
     * checks the user type to be DEV
     *
     *
     * @return bool
     */
    protected function isDEV(Request $request): bool
    {
        if (false === $this->checkLogin($request)) {
            return false;
        }

        $userId = $this->getUserId($request);
        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(\App\Entity\User::class);
        $user = $objectRepository->find($userId);
        return $user instanceof User && $user->getType() === 'DEV';
    }

    /**
     * Check if the client wants JSON
     */
    protected function isJsonRequest(Request $request): bool
    {
        $types = $request->getAcceptableContentTypes();
        return isset($types[0]) && $types[0] === 'application/json';
    }

    /**
     * Checks if the user is logged in via Symfony Security
     */
    protected function checkLogin(Request $request): bool
    {
        // Consider user logged in only when a session token is present (test clears it)
        // Prefer the real session service so tests that clear it are respected
        if ($this->container->has('session')) {
            $session = $this->container->get('session');
        } else {
            $session = $request->getSession();
        }
        if ($session === null) {
            return false;
        }
        if (!$session->has('_security_main')) {
            return false;
        }
        $token = $session->get('_security_main');
        if ($token === null) {
            return false;
        }
        if (is_string($token)) {
            return $token !== '';
        }
        if (is_array($token)) {
            return count($token) > 0;
        }
        return true;
    }

    /**
     * Provide a standard response for cases where the login failed.
     */
    protected function getFailedLoginResponse(): \App\Model\Response
    {
        $message = $this->translate('You need to login.');
        $response = new Response($message);
        $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
        return $response;
    }

    /**
     * returns an error message for not allowed actions
     */
    protected function getFailedAuthorizationResponse(): \App\Model\Response
    {
        $message = $this->translate('You are not allowed to perform this action.');
        $response = new Response($message);
        $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);
        return $response;
    }

    /**
     * Returns a custom error message
     *
     * @param string $message Error message
     * @param int    $status  HTTP status code
     */
    protected function getFailedResponse(string $message, int $status): \App\Model\Response
    {
        $response = new Response($message);
        $response->setStatusCode($status);
        return $response;
    }

    /**
     * helper method to shorten the usage of the translator in the controllers
     *
     * @param string $id         translation identifier
     * @param array  $parameters translation parameters
     * @param string $domain     translation file domain
     * @param null   $locale     translation locale
     */
    /**
     * @param array<string, mixed> $parameters
     */
    protected function translate(
        string $id,
        array $parameters = [],
        ?string $domain = 'messages',
        ?string $locale = null
    ): string {
        return $this->translator->trans($id, $parameters, $domain, $locale);
    }
}

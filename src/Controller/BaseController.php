<?php

/**
 * basic controller to share some features with the child controllers.
 *
 * PHP version 5
 *
 * @category  Controller
 *
 * @author    Mathias Lieber <mathias.lieber@netresearch.de>
 * @copyright 2012 Netresearch App Factory AG
 * @license   No license
 *
 * @see      http://www.netresearch.de
 */

namespace App\Controller;

use App\Entity\User;
use App\Model\Response;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * BaseController.php.
 *
 * @category Controller
 *
 * @author   Mathias Lieber <mathias.lieber@netresearch.de>
 * @license  No license
 *
 * @see     http://www.netresearch.de
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

    /**
     * Check if user is logged in via Symfony Security.
     */
    protected function isLoggedIn(Request $request): bool
    {
        // Require a fully authenticated session (no remember-me)
        return $this->isGranted('IS_AUTHENTICATED_FULLY');
    }

    /**
     * Returns the user id.
     *
     * @return int User ID
     *
     * @throw AccessDeniedException
     */
    protected function getUserId(Request $request): int
    {
        if (!$this->isLoggedIn($request)) {
            throw new AccessDeniedException('No user logged in');
        }

        // Get user from Symfony security context
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('No user logged in');
        }

        // Handle impersonation through Symfony's built-in functionality
        return (int) $user->getId();
    }

    /**
     * Redirects to the login page.
     */
    protected function login(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response
    {
        if (!$request->isXmlHttpRequest()
            && !$this->isJsonRequest($request)
        ) {
            return $this->redirectToRoute('_login');
        }

        return new Response($this->generateUrl('_login'), \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);
    }

    /**
     * checks the user type to be PL.
     */
    protected function isPl(Request $request): bool
    {
        if (false === $this->checkLogin($request)) {
            return false;
        }

        $userId = $this->getUserId($request);
        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(User::class);
        $user = $objectRepository->find($userId);

        return $user instanceof User && 'PL' === $user->getType();
    }

    /**
     * checks the user type to be DEV.
     */
    protected function isDEV(Request $request): bool
    {
        if (false === $this->checkLogin($request)) {
            return false;
        }

        $userId = $this->getUserId($request);
        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(User::class);
        $user = $objectRepository->find($userId);

        return $user instanceof User && 'DEV' === $user->getType();
    }

    /**
     * Check if the client wants JSON.
     */
    protected function isJsonRequest(Request $request): bool
    {
        $types = $request->getAcceptableContentTypes();

        return isset($types[0]) && 'application/json' === $types[0];
    }

    /**
     * Checks if the user is logged in via Symfony Security.
     */
    protected function checkLogin(Request $request): bool
    {
        // Consider user logged in only when a session token is present (test clears it)
        // Prefer the real session service so tests that clear it are respected
        $session = $this->container->has('session') ? $this->container->get('session') : $request->getSession();

        if (null === $session) {
            return false;
        }

        if (!$session->has('_security_main')) {
            return false;
        }

        $token = $session->get('_security_main');
        if (null === $token) {
            return false;
        }

        if (is_string($token)) {
            return '' !== $token;
        }

        if (is_array($token)) {
            return $token !== [];
        }

        return true;
    }

    /**
     * Provide a standard response for cases where the login failed.
     */
    protected function getFailedLoginResponse(): Response
    {
        $message = $this->translate('You need to login.');
        $response = new Response($message);
        $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);

        return $response;
    }

    /**
     * returns an error message for not allowed actions.
     */
    protected function getFailedAuthorizationResponse(): Response
    {
        $message = $this->translate('You are not allowed to perform this action.');
        $response = new Response($message);
        $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

        return $response;
    }

    /**
     * Returns a custom error message.
     *
     * @param string $message Error message
     * @param int    $status  HTTP status code
     */
    protected function getFailedResponse(string $message, int $status): Response
    {
        $response = new Response($message);
        $response->setStatusCode($status);

        return $response;
    }

    /**
     * helper method to shorten the usage of the translator in the controllers.
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
        ?string $locale = null,
    ): string {
        return $this->translator->trans($id, $parameters, $domain, $locale);
    }
}

<?php

declare(strict_types=1);

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
use App\Enum\UserType;
use App\Model\Response;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

use const E_USER_DEPRECATED;

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

    protected ParameterBagInterface $params;

    protected TranslatorInterface $translator;

    protected KernelInterface $kernel;

    protected ManagerRegistry $doctrineRegistry;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setCoreDependencies(
        ManagerRegistry $managerRegistry,
        ParameterBagInterface $parameterBag,
        TranslatorInterface $translator,
        KernelInterface $kernel,
    ): void {
        $this->managerRegistry = $managerRegistry;
        $this->doctrineRegistry = $managerRegistry; // BC for legacy usages
        $this->params = $parameterBag;
        $this->translator = $translator;
        $this->kernel = $kernel;
    }

    /**
     * Check if user is logged in using Symfony Security.
     *
     * @deprecated Use isGranted('IS_AUTHENTICATED_FULLY') directly
     *
     * @throws Exception
     */
    protected function isLoggedIn(Request $request): bool
    {
        @trigger_error('Method ' . __METHOD__ . ' is deprecated, use isGranted(\'IS_AUTHENTICATED_FULLY\') directly', E_USER_DEPRECATED);

        return $this->isGranted('IS_AUTHENTICATED_FULLY');
    }

    /**
     * Returns the user id.
     *
     * @throws AccessDeniedException
     * @throws Exception
     *
     * @return int User ID
     */
    protected function getUserId(Request $request): int
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedException('No user logged in');
        }

        // Handle impersonation through Symfony's built-in functionality
        return (int) $user->getId();
    }

    /**
     * Redirects to the login page.
     *
     * @throws Exception
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
     * Check if the current user has a specific user type.
     *
     * @deprecated Use Security expressions with #[Security] attribute instead
     */
    protected function hasUserType(Request $request, UserType $userType): bool
    {
        @trigger_error('Method ' . __METHOD__ . ' is deprecated, use #[Security] attribute with expressions instead', E_USER_DEPRECATED);

        $currentUser = $this->getUser();
        if ($currentUser instanceof User) {
            return $userType === $currentUser->getType();
        }

        return false;
    }

    /**
     * Checks the user type to be PL.
     *
     * @deprecated Use #[Security("is_granted('ROLE_ADMIN') or (is_granted('ROLE_USER') and user.getType() == 'PL')")] instead
     */
    protected function isPl(Request $request): bool
    {
        @trigger_error('Method ' . __METHOD__ . ' is deprecated, use #[Security] attribute with user type check instead', E_USER_DEPRECATED);

        return $this->hasUserType($request, UserType::PL);
    }

    /**
     * Checks the user type to be DEV.
     *
     * @deprecated Use #[Security("is_granted('ROLE_USER') and user.getType() == 'DEV')")] instead
     */
    protected function isDEV(Request $request): bool
    {
        @trigger_error('Method ' . __METHOD__ . ' is deprecated, use #[Security] attribute with user type check instead', E_USER_DEPRECATED);

        return $this->hasUserType($request, UserType::DEV);
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
     *
     * @deprecated Use isGranted('IS_AUTHENTICATED_FULLY') directly
     */
    protected function checkLogin(Request $request): bool
    {
        @trigger_error('Method ' . __METHOD__ . ' is deprecated, use isGranted(\'IS_AUTHENTICATED_FULLY\') directly', E_USER_DEPRECATED);

        return $this->isGranted('IS_AUTHENTICATED_FULLY');
    }

    /**
     * Provide a standard response for cases where the login failed.
     *
     * @throws Exception
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
     *
     * @throws Exception
     */
    protected function getFailedAuthorizationResponse(): Response
    {
        $message = $this->translate('You are not allowed to perform this action.');
        $response = new Response($message);
        $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

        return $response;
    }

    /**
     * helper method to shorten the usage of the translator in the controllers.
     *
     * @param string               $id         translation identifier
     * @param array<string, mixed> $parameters
     * @param string               $domain     translation file domain
     * @param null                 $locale     translation locale
     *
     * @throws Exception
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

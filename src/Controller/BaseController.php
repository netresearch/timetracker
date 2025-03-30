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
use App\Helper\LocalizationHelper;
use App\Helper\LoginHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
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
    /** @var ParameterBagInterface */
    protected $params;

    /** @var EntityManagerInterface */
    protected $entityManager;

    /** @var SessionInterface */
    protected $session;

    /** @var TranslatorInterface */
    protected $translator;

    /** @var RouterInterface */
    protected $router;

    /** @var KernelInterface */
    protected $kernel;

    /** @var ManagerRegistry */
    protected $doctrineRegistry;

    /**
     * @required
     */
    public function setParameters(ParameterBagInterface $parameterBag): void
    {
        $this->params = $parameterBag;
    }

    /**
     * @required
     */
    public function setEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @required
     */
    public function setSession(SessionInterface $session): void
    {
        $this->session = $session;
    }

    /**
     * @required
     */
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    /**
     * @required
     */
    public function setRouter(RouterInterface $router): void
    {
        $this->router = $router;
    }

    /**
     * @required
     */
    public function setKernel(KernelInterface $kernel): void
    {
        $this->kernel = $kernel;
    }

    /**
     * @required
     */
    public function setDoctrineRegistry(ManagerRegistry $managerRegistry): void
    {
        $this->doctrineRegistry = $managerRegistry;
    }

    /**
     * set up function before actions are dispatched
     *
     *
     */
    public function preExecute(Request $request): void
    {
        if (!$this->checkLogin($request)) {
            return;
        }

        $managerRegistry = $this->getDoctrine();
        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(\App\Entity\User::class);
        $user = $objectRepository->find($this->getUserId($request));

        if (!is_object($user)) {
            return;
        }

        $locale = LocalizationHelper::normalizeLocale($user->getLocale());

        $request->setLocale($locale);
    }

    /**
     * Check if user is logged in via Symfony Security
     *
     *
     * @return bool
     */
    protected function isLoggedIn(Request $request)
    {
        // Use Symfony security component to check if user is authenticated
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return true;
        }

        return $this->isGranted('IS_AUTHENTICATED_REMEMBERED');
    }

    /**
     * Returns the user id
     *
     *
     * @return int User ID
     * @throw AccessDeniedException
     */
    protected function getUserId(Request $request)
    {
        if (!$this->isLoggedIn($request)) {
            throw new AccessDeniedException('No user logged in');
        }

        // Get user from Symfony security context
        $user = $this->getUser();

        // Handle impersonation through Symfony's built-in functionality
        return $user->getId();
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
            return $this->redirect($this->generateUrl('_login'));
        }

        return new Response($this->generateUrl('_login'), 403);
    }

    /**
     * checks the user type to be PL
     *
     *
     * @return bool
     */
    protected function isPl(Request $request)
    {
        if (false === $this->checkLogin($request)) {
            return false;
        }

        $userId = $this->getUserId($request);
        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->getDoctrine()->getRepository(\App\Entity\User::class);
        $user = $objectRepository->find($userId);

        return is_object($user) && 'PL' == $user->getType();
    }


    /**
     * checks the user type to be DEV
     *
     *
     * @return bool
     */
    protected function isDEV(Request $request)
    {
        if (false === $this->checkLogin($request)) {
            return false;
        }

        $userId = $this->getUserId($request);
        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->getDoctrine()->getRepository(\App\Entity\User::class);
        $user = $objectRepository->find($userId);

        return is_object($user) && 'DEV' == $user->getType();
    }

    /**
     * Check if the client wants JSON
     */
    protected function isJsonRequest(Request $request): bool
    {
        $types = $request->getAcceptableContentTypes();
        return isset($types[0]) && $types[0] == 'application/json';
    }

    /**
     * Checks if the user is logged in via Symfony Security
     *
     * @param Request $request The request object
     *
     * @return bool
     */
    protected function checkLogin(Request $request)
    {
        // Only use Symfony's security component to check authentication
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return true;
        }

        return $this->isGranted('IS_AUTHENTICATED_REMEMBERED');
    }

    /**
     * Provide a standard response for cases where the login failed.
     */
    protected function getFailedLoginResponse(): \App\Model\Response
    {
        $message = $this->translate('You need to login.');
        $response = new Response($message);
        $response->setStatusCode(401);
        return $response;
    }

    /**
     * returns an error message for not allowed actions
     */
    protected function getFailedAuthorizationResponse(): \App\Model\Response
    {
        $message = $this->translate('You are not allowed to perform this action.');
        $response = new Response($message);
        $response->setStatusCode(403);
        return $response;
    }

    /**
     * Returns a custom error message
     *
     * @param string $message Error message
     * @param int    $status  HTTP status code
     */
    protected function getFailedResponse($message, int $status): \App\Model\Response
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
     *
     * @return mixed
     */
    protected function translate(
        string $id,
        array $parameters = [],
        ?string $domain = 'messages',
        ?string $locale = null
    ) {
        return $this->translator->trans($id, $parameters, $domain, $locale);
    }
}

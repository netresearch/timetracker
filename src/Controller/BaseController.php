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
use App\Helper\LocalizationHelper as LocalizationHelper;
use App\Helper\LoginHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

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

    /**
     * @required
     */
    public function setParameters(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    /**
     * @required
     */
    public function setEntityManager(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @required
     */
    public function setSession(SessionInterface $session)
    {
        $this->session = $session;
    }

    /**
     * @required
     */
    public function setTranslator(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * set up function before actions are dispatched
     *
     * @param Request $request
     *
     * @return void
     */
    public function preExecute(Request $request)
    {
        if (!$this->checkLogin($request))
            return;

        $doctrine = $this->getDoctrine();
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $doctrine->getRepository(\App\Entity\User::class);
        $user = $userRepo->find($this->getUserId($request));

        if (!is_object($user)) {
            return;
        }

        $locale = LocalizationHelper::normalizeLocale($user->getLocale());

        $request->setLocale($locale);
        $this->get('translator')->setLocale($locale);
    }

    /**
     * Check if user is logged in via Symfony Security
     *
     * @param Request $request
     *
     * @return bool
     */
    protected function isLoggedIn(Request $request)
    {
        // Use Symfony security component to check if user is authenticated
        return $this->isGranted('IS_AUTHENTICATED_FULLY');
    }

    /**
     * Returns the user id
     *
     * @param Request $request
     *
     * @return int User ID
     *
     * @throw AccessDeniedException
     */
    protected function getUserId(Request $request)
    {
        if (!$this->isLoggedIn($request)) {
            throw new AccessDeniedException('No user logged in');
        }

        // Get user from Symfony security context
        $user = $this->getUser();

        if ($request->getSession()->has('loginRealId')) {
            return $request->getSession()->get('loginRealId');
        }

        return $user->getId();
    }

    /**
     * Check if the current user may impersonate as the given simulated user ID
     */
    protected function mayImpersonate(User $realUser, $simulatedUserId): bool
    {
        // Check if user is admin or has special role
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        $serviceUserNames = explode(',', $this->params->get('service_users'));
        return in_array($realUser->getUsername(), $serviceUserNames);
    }

    /**
     * Redirects to the login page
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    protected function login(Request $request)
    {
        if (!$request->isXmlHttpRequest()
            && !$this->isJsonRequest($request)
        ) {
            return $this->redirect($this->generateUrl('_login'));
        } else {
            return new Response($this->generateUrl('_login'), 403);
        }
    }

    /**
     * checks the user type to be PL
     *
     * @param Request $request
     *
     * @return bool
     */
    protected function isPl(Request $request)
    {
        if (false === $this->checkLogin($request)) {
            return false;
        }

        $userId = $this->getUserId($request);
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(\App\Entity\User::class);
        $user = $userRepo->find($userId);

        return is_object($user) && 'PL' == $user->getType();
    }


    /**
     * checks the user type to be DEV
     *
     * @param Request $request
     *
     * @return bool
     */
    protected function isDEV(Request $request)
    {
        if (false === $this->checkLogin($request)) {
            return false;
        }

        $userId = $this->getUserId($request);
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(\App\Entity\User::class);
        $user = $userRepo->find($userId);

        return is_object($user) && 'DEV' == $user->getType();
    }

    /**
     * Check if the client wants JSON
     */
    protected function isJsonRequest(Request $request)
    {
        $types = $request->getAcceptableContentTypes();
        return isset($types[0]) && $types[0] == 'application/json';
    }

    /**
     * Checks if the user is logged in. Either in the session or via cookie
     *
     * @param Request $request The request object
     *
     * @return bool
     */
    protected function checkLogin(Request $request)
    {
        // First, check if the user is authenticated via Symfony security
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return true;
        }

        // For backward compatibility, also check cookie-based authentication
        $userId = (int) LoginHelper::getCookieUserId();
        if ($userId > 0) {
            /* @var $user User */
            $user = $this->entityManager
                ->getRepository(User::class)
                ->find($userId);

            if ($user && LoginHelper::checkCookieUserName($user->getUsername(), $this->params->get('secret'))) {
                // Authenticate in Symfony security system
                $this->setLoggedIn($request, $user);
                return true;
            }
        }

        return false;
    }

    /**
     * Provide a standard response for cases where the login failed.
     *
     * @return Response
     */
    protected function getFailedLoginResponse()
    {
        $message = $this->translate('You need to login.');
        $response = new Response($message);
        $response->setStatusCode(401);
        return $response;
    }

    /**
     * returns an error message for not allowed actions
     *
     * @return Response
     */
    protected function getFailedAuthorizationResponse()
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
     *
     * @return Response
     */
    protected function getFailedResponse($message, $status)
    {
        $response = new Response($message);
        $response->setStatusCode($status);
        return $response;
    }

    /**
     * Sets the session values to "logged in"
     *
     * @param Request $request The request object
     * @param User    $user      user object
     * @param bool    $setCookie set a cookie or not
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    protected function setLoggedIn(Request $request, $user, $setCookie = true)
    {
        $session = $request->getSession();
        $session->set('loginTime', date('Y-m-d H:i:s'));
        $session->set('loginId', $user->getId());
        $session->set('loginName', $user->getUsername());
        $session->set('loginType', $user->getType());

        // Check for impersonation
        $simulatedUserId = $request->query->get('simulateUserId');
        if (!empty($simulatedUserId)
            && $this->mayImpersonate($user, $simulatedUserId)
        ) {
            $simulatedUser = $this->entityManager
                ->getRepository(User::class)
                ->find($simulatedUserId);

            if ($simulatedUser) {
                $session->set('loginRealId', $session->get('loginId'));
                $session->set('loginRealName', $session->get('loginName'));
                $session->set('loginRealType', $session->get('loginType'));
                $session->set('loginId', $simulatedUser->getId());
                $session->set('loginName', $simulatedUser->getUsername());
                $session->set('loginType', $simulatedUser->getType());
            }
        }

        if (true === $setCookie) {
            // set login cookie for autologin and 30 days persistence
            LoginHelper::setCookie($user->getId(), $user->getUsername(), $this->params->get('secret'));
        }

        $this->addFlash('success', $this->translate('Login successful.'));

        return $this->redirectToRoute('_start');
    }

    /**
     * sets the user logged out
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function setLoggedOut(Request $request)
    {
        $session = $request->getSession();

        // if we're impersonating someone - get back to real user
        if ($session->has('loginRealId')) {
            $session->set('loginId', $session->get('loginRealId'));
            $session->set('loginName', $session->get('loginRealName'));
            $session->set('loginType', $session->get('loginRealType'));
            $session->remove('loginRealId');
            $session->remove('loginRealName');
            $session->remove('loginRealType');

            return $this->redirectToRoute('_start');
        }

        // set logout flash message
        $this->addFlash('success', $this->translate('You are now logged out.'));

        $session->clear();
        LoginHelper::deleteCookie();

        return $this->redirectToRoute('_login');
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
        $id, array $parameters = array(), $domain = 'messages', $locale = null
    ) {
        return $this->translator->trans($id, $parameters, $domain, $locale);
    }
}

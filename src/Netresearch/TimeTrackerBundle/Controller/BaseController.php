<?php
/**
 * basic controller to share some features with the child controllers
 *
 * PHP version 5
 *
 * @category  Controller
 * @package   Netresearch\TimeTrackerBundle\Controller
 * @author    Mathias Lieber <mathias.lieber@netresearch.de>
 * @copyright 2012 Netresearch App Factory AG
 * @license   No license
 * @link      http://www.netresearch.de
 */

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Entity\User;
use Netresearch\TimeTrackerBundle\Helper\LocalizationHelper as LocalizationHelper;
use Netresearch\TimeTrackerBundle\Helper\LoginHelper;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Netresearch\TimeTrackerBundle\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Class BaseController
 *
 * @category Controller
 * @package  Netresearch\TimeTrackerBundle\Controller
 * @author   Mathias Lieber <mathias.lieber@netresearch.de>
 * @license  No license
 * @link     http://www.netresearch.de
 */
class BaseController extends Controller
{

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
        $user = $doctrine->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($this->getUserId($request));

        if (!is_object($user)) {
            return;
        }

        $locale = LocalizationHelper::normalizeLocale($user->getLocale());

        $request->setLocale($locale);
    }


    /**
     * check the login status
     *
     * @param Request $request
     *
     * @return mixed
     */
    protected function isLoggedIn(Request $request)
    {
        return $request->getSession()->get('loggedIn');
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
        $realUserId      = $request->getSession()->get('loginId');
        $simulatedUserId = $request->get('user', null);
        if ($realUserId == $simulatedUserId) {
            return $realUserId;
        }

        if ($simulatedUserId === '') {
            $simulatedUserId = null;
        }
        if ($simulatedUserId !== null) {
            $realUser = $this->getDoctrine()
                ->getRepository('NetresearchTimeTrackerBundle:User')
                ->find($realUserId);
            if (!$this->mayImpersonate($realUser, $simulatedUserId)) {
                throw new AccessDeniedException('This user may not simulate other users');
            }
            return (int) $simulatedUserId;
        }
        return (int) $realUserId;
    }

    /**
     * Check if the current user may impersonate as the given simulated user ID
     */
    protected function mayImpersonate(User $realUser, $simulatedUserId): bool
    {
        $serviceUserNames = explode(',', $this->container->getParameter('service_users'));
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
        if (!$request->isXmlHttpRequest()) {
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
        $user = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($userId);

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
        $user = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($userId);

        return is_object($user) && 'DEV' == $user->getType();
    }


    /**
     * Returns true if a user is logged in or can authenticate by cookie
     *
     * @param Request $request
     *
     * @return bool
     */
    protected function checkLogin(Request $request)
    {
        if ($this->isLoggedIn($request)) {
            return true;
        }

        $userId = (int) LoginHelper::getCookieUserId();

        if (1 > $userId) {
            return false;
        }

        /* @var $user User */
        $user = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->findOneById($userId);
        if (!is_object($user)) {
            LoginHelper::deleteCookie();
            return false;
        }

        // Re-Login with "keep me logged in" cookie
        if (LoginHelper::checkCookieUserName($user->getUsername(), $this->container->getParameter('secret'))) {
            $this->setLoggedIn($request, $user, true);
        } else {
            LoginHelper::deleteCookie();
            return false;
        }

        return true;
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
     * Handles all after-login stuff
     *
     * @param Request $request
     * @param User    $user      user object
     * @param bool    $setCookie set a cookie or not
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    protected function setLoggedIn(Request $request, $user, $setCookie = true)
    {
        $session = $request->getSession();

        if (! is_object($user)) {
            $session->getFlashBag()->add(
                'error',
                $this->translate('Could not find user.')
            );

            return $this->render(
                'NetresearchTimeTrackerBundle:Default:login.html.twig',
                [
                    'locale'   => 'en',
                    'apptitle' => $this->container->getParameter('app_title'),
                ]
            );
        }

        $session->set('loggedIn', true);
        $session->set('loginUsername', $user->getUsername());
        $session->set('loginId', $user->getId());

        // Set login cookies, if wanted
        if ($setCookie) {
            LoginHelper::setCookie(
                $user->getId(), $user->getUsername(),
                $this->container->getParameter('secret')
            );
        }

        return $this->redirect($this->generateUrl('_start'));
    }


    /**
     * logout of an user
     *
     * @param Request $request
     *
     * @return void
     */
    protected function setLoggedOut(Request $request)
    {
        // delete login cookies
        LoginHelper::deleteCookie();

        $request->getSession()->clear();
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
        $translator = $this->get('translator');

        $locale = (is_null($locale)) ? $translator->getLocale() : $locale;

        return $translator->trans($id, $parameters, $domain, $locale);
    }
}

<?php
/**
 * basic controller to share some features with the child controllers
 *
 * PHP version 8
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
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Class BaseController
 *
 * @category Controller
 * @package  App\Controller
 * @author   Mathias Lieber <mathias.lieber@netresearch.de>
 * @license  No license
 * @link     http://www.netresearch.de
 */
class BaseController extends AbstractController
{

    /**
     * set up function before actions are dispatched
     *
     *
     * @return void
     */
    public function preExecute(Request $request)
    {
        if (!$this->checkLogin($request))
            return;

        $doctrine = $this->getDoctrine();
        $user = $doctrine->getRepository('App:User')
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
     *
     * @return mixed
     */
    protected function isLoggedIn(Request $request)
    {
        return $request->getSession()->get('loggedIn');
    }


    /**
     * returns the user id
     *
     *
     * @return mixed
     */
    protected function getUserId(Request $request)
    {
        return $request->getSession()->get('loginId');
    }

    /**
     * Redirects to the login page
     *
     *
     */
    protected function login(Request $request): \App\Model\Response|\Symfony\Component\HttpFoundation\RedirectResponse
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
            ->getRepository('App:User')
            ->find($userId);

        return ('PL' == $user->getType());
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
        $user = $this->getDoctrine()
            ->getRepository('App:User')
            ->find($userId);

        return ('DEV' == $user->getType());
    }


    /**
     * Returns true if a user is logged in or can authenticate by cookie
     *
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
            ->getRepository('App:User')
            ->findOneById($userId);

        // Re-Login by cookie
        if (LoginHelper::checkCookieUserName($user->getUsername())) {
            $this->setLoggedIn($request, $user, true);
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
        $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
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
        $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);
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
     * @param User    $user      user object
     * @param bool    $setCookie set a cookie or not
     *
     */
    protected function setLoggedIn(Request $request, $user, $setCookie = true): \App\Model\Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $session = $request->getSession();

        if (! is_object($user)) {
            $session->getFlashBag()->add(
                'error',
                $this->translate('Could not find user.')
            );

            return $this->render(
                'App:Default:login.html.twig',
                [
                    'locale'   => 'en',
                ]
            );
        }

        $session->set('loggedIn', true);
        $session->set('loginUsername', $user->getUsername());
        $session->set('loginId', $user->getId());

        // Set login cookies, if wanted
        if ($setCookie) {
            LoginHelper::setCookie($user->getId(), $user->getUsername());
        }

        return $this->redirect($this->generateUrl('_start'));
    }


    /**
     * logout of an user
     *
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

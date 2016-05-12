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
use Symfony\Component\HttpFoundation\Response;
use Netresearch\TimeTrackerBundle\Helper;

use \Doctrine AS Doctrine;

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
     * @return void
     */
    public function preExecute()
    {
        //$session = $this->getRequest()->getSession();

        if (!$this->checkLogin())
            return;

        $doctrine = $this->getDoctrine();
        $user = $doctrine->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($this->_getUserId());

        if (!is_object($user)) {
            return;
        }

        $locale = LocalizationHelper::normalizeLocale($user->getLocale());

        $this->getRequest()->setLocale($locale);
        // $session->setLocale($locale);
    }


    /**
     * check the login status
     *
     * @return mixed
     */
    protected function _isLoggedIn()
    {
        return $this->getRequest()->getSession()->get('loggedIn');
    }


    /**
     * returns the user id
     *
     * @return mixed
     */
    protected function _getUserId()
    {
        return $this->getRequest()->getSession()->get('loginId');
    }

    /**
     * Redirects to the login page
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    protected function _login()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->redirect($this->generateUrl('_login'));
        } else {
            return new Response($this->generateUrl('_login'), 403);
        }
    }

    /**
     * checks the usertype to be PL
     *
     * @return bool
     */
    protected function _isPl()
    {
        $userId = $this->_getUserId();
        $user = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($userId);

        return ('PL' == $user->getType());
    }


    /**
     * Returns true if a user is logged in or can authenticate by cookie
     *
     * @return bool
     */
    protected function checkLogin()
    {
        if ($this->_isLoggedIn()) {
            return true;
        }

        $userId = (int) LoginHelper::getCookieUserId();

        if (1 > $userId) {
            return false;
        }

        $user = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->findOneById($userId);

        // Re-Login by cookie
        if (LoginHelper::checkCookieUserName($user->getUsername())) {
            $this->setLoggedIn($user, true);
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
     * Handles all after-login stuff
     *
     * @param User $user      user object
     * @param bool $setCookie set a cookie or not
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    protected function setLoggedIn($user, $setCookie = true)
    {
        $session = $this->getRequest()->getSession();

        if (! is_object($user)) {
            $session->setFlash(
                'error',
                $this->translate('Could not find user.')
            );

            return $this->render(
                'NetresearchTimeTrackerBundle:Default:login.html.twig',
                array('locale' => 'en')
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
     * @return void
     */
    protected function setLoggedOut()
    {
        // delete login cookies
        LoginHelper::deleteCookie();

        $this->getRequest()->getSession()->clear();
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

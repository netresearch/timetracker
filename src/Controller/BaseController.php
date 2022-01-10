<?php declare(strict_types=1);
/**
 * basic controller to share some features with the child controllers.
 *
 * PHP version 8
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

use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Entity\User;
use App\Helper\LocalizationHelper;
use App\Helper\LoginHelper;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Class BaseController.
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
    protected Request $request;
    protected Session $session;

    public function __construct(
        protected ManagerRegistry $doctrine,
        protected RequestStack $requestStack,
        protected TranslatorInterface $translator,
        protected ParameterBagInterface $params
    ) {
        $this->request = $requestStack->getCurrentRequest();
        $this->session = $requestStack->getCurrentRequest()->getSession();
    }

    /**
     * set up function before actions are dispatched.
     */
    public function preExecute(): void
    {
        if (!$this->checkLogin()) {
            return;
        }

        $doctrine = $this->doctrine;
        $user     = $doctrine->getRepository('App:User')
            ->find($this->getUserId())
        ;

        if (!\is_object($user)) {
            return;
        }

        $locale = LocalizationHelper::normalizeLocale($user->getLocale());

        $this->request->setLocale($locale);
    }

    /**
     * check the login status.
     */
    protected function isLoggedIn(): mixed
    {
        return $this->session->get('loggedIn');
    }

    /**
     * returns the user id.
     */
    protected function getUserId(): mixed
    {
        return $this->session->get('loginId');
    }

    /**
     * Redirects to the login page.
     */
    protected function login(): Response|RedirectResponse
    {
        if (!$this->request->isXmlHttpRequest()) {
            return $this->redirectToRoute('_login');
        }

        return new Response($this->generateUrl('_login'), 403);
    }

    /**
     * checks the user type to be PL.
     */
    protected function isPl(): bool
    {
        if (false === $this->checkLogin()) {
            return false;
        }

        $userId = $this->getUserId();
        $user   = $this->doctrine
            ->getRepository('App:User')
            ->find($userId)
        ;

        return 'PL' === $user->getType();
    }

    /**
     * checks the user type to be DEV.
     */
    protected function isDEV(): bool
    {
        if (false === $this->checkLogin()) {
            return false;
        }

        $userId = $this->getUserId();
        $user   = $this->doctrine
            ->getRepository('App:User')
            ->find($userId)
        ;

        return 'DEV' === $user->getType();
    }

    /**
     * Returns true if a user is logged in or can authenticate by cookie.
     */
    protected function checkLogin(): bool
    {
        if ($this->isLoggedIn()) {
            return true;
        }

        $userId = (int) LoginHelper::getCookieUserId();

        if (1 > $userId) {
            return false;
        }

        /** @var $user User */
        $user = $this->doctrine
            ->getRepository('App:User')
            ->findOneById($userId)
        ;

        // Re-Login by cookie
        if (LoginHelper::checkCookieUserName($user->getUsername())) {
            $this->setLoggedIn($user, true);
        }

        return true;
    }

    /**
     * Provide a standard response for cases where the login failed.
     */
    protected function getFailedLoginResponse(): Response
    {
        $message  = $this->t('You need to login.');
        $response = new Response($message);
        $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);

        return $response;
    }

    /**
     * returns an error message for not allowed actions.
     */
    protected function getFailedAuthorizationResponse(): Response
    {
        $message  = $this->t('You are not allowed to perform this action.');
        $response = new Response($message);
        $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

        return $response;
    }

    /**
     * Returns a custom error message.
     */
    protected function getFailedResponse(string $message, int $status): Response
    {
        $response = new Response($message);
        $response->setStatusCode($status);

        return $response;
    }

    /**
     * Handles all after-login stuff.
     */
    protected function setLoggedIn(User $user, bool $setCookie = false): Response|RedirectResponse
    {
        if (!\is_object($user)) {
            $this->session->getFlashBag()->add(
                'error',
                $this->t('Could not find user.')
            );

            return $this->render('login.html.twig', ['locale' => 'en']);
        }

        $this->session->set('loggedIn', true);
        $this->session->set('loginUsername', $user->getUsername());
        $this->session->set('loginId', $user->getId());

        // Set login cookies, if wanted
        if ($setCookie || $this->request->request->has('loginCookie')) {
            LoginHelper::setCookie($user->getId(), $user->getUsername());
        }

        return $this->redirectToRoute('_start');
    }

    /**
     * logout of an user.
     */
    protected function setLoggedOut(): void
    {
        // delete login cookies
        LoginHelper::deleteCookie();

        $this->session->clear();
    }

    protected function t(
        string $id,
        array $parameters = [],
        string $domain = 'messages',
        string $locale = null
    ): mixed {
        $locale = (null === $locale) ? $this->translator->getLocale() : $locale;

        return $this->translator->trans($id, $parameters, $domain, $locale);
    }
}

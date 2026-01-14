<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class AccessDeniedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 5],
        ];
    }

    public function onKernelException(ExceptionEvent $exceptionEvent): void
    {
        $throwable = $exceptionEvent->getThrowable();

        if (!$throwable instanceof AccessDeniedException) {
            return;
        }

        $request = $exceptionEvent->getRequest();
        $hasRememberMeCookie = $request->cookies->has('REMEMBERME');
        $user = $this->security->getUser();

        // Case 1: User is not authenticated at all
        // If they have a stale remember_me cookie, clear it and redirect to login
        if (!$user instanceof UserInterface) {
            $loginUrl = $this->router->generate('_login');
            $response = new RedirectResponse($loginUrl);

            // Clear invalid remember_me cookie if present
            if ($hasRememberMeCookie) {
                $response->headers->clearCookie('REMEMBERME', '/');
            }

            $exceptionEvent->setResponse($response);

            return;
        }

        // Case 2: User is authenticated via remember_me but not fully authenticated
        // This happens when IS_AUTHENTICATED_FULLY is required but user only has remember_me
        // UX: Clear remember_me cookie and redirect to login so they can re-authenticate
        // (Without clearing the cookie, the login page would redirect them back, causing a loop)
        if (!$this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            $loginUrl = $this->router->generate('_login');
            $response = new RedirectResponse($loginUrl);

            // Clear the remember_me cookie to allow fresh login
            if ($hasRememberMeCookie) {
                $response->headers->clearCookie('REMEMBERME', '/');
            }

            $exceptionEvent->setResponse($response);

            return;
        }

        // Case 3: User is fully authenticated but lacks required permissions
        // This is a real "forbidden" case (e.g., non-admin accessing /admin)
        $response = new Response('You are not allowed to perform this action.', Response::HTTP_FORBIDDEN);
        $exceptionEvent->setResponse($response);
    }
}

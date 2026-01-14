<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
            // Use secure=false to ensure cookie is cleared over HTTP (dev) and HTTPS (prod)
            if ($hasRememberMeCookie) {
                $response->headers->clearCookie('REMEMBERME', '/', null, false);
            }

            $exceptionEvent->setResponse($response);

            return;
        }

        // Case 2: User is authenticated via remember_me but not fully authenticated
        // This happens when IS_AUTHENTICATED_FULLY is required but user only has remember_me
        // UX: Redirect to logout to properly clear session and cookies, then user lands on login
        if (!$this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            $logoutUrl = $this->router->generate('_logout');
            $response = new RedirectResponse($logoutUrl);
            $exceptionEvent->setResponse($response);

            return;
        }

        // Case 3: User is fully authenticated but lacks required permissions
        // This is a real "forbidden" case (e.g., non-admin accessing /admin)
        // Let Symfony's default exception handling render the error403.html.twig template
        // which provides a styled page with navigation options
    }
}

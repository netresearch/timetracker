<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Bundle\SecurityBundle\Security;

final class AccessDeniedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private RouterInterface $router
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 5],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if (!$throwable instanceof AccessDeniedException) {
            return;
        }

        // If user is not authenticated, redirect to login instead of showing 403
        if (!$this->security->getUser()) {
            $loginUrl = $this->router->generate('_login');
            $response = new RedirectResponse($loginUrl);
            $event->setResponse($response);
            return;
        }

        // Preserve legacy 403 message behavior for authenticated users (used in tests)
        $response = new Response('You are not allowed to perform this action.', Response::HTTP_FORBIDDEN);
        $event->setResponse($response);
    }
}

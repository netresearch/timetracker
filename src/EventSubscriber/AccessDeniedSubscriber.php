<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class AccessDeniedSubscriber implements EventSubscriberInterface
{
    private const string MIME_TYPE_JSON = 'application/json';

    public function __construct(
        private Security $security,
        private RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 15 to run before ExceptionSubscriber (priority 10)
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 15],
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

        // A login that is half-done (password ok, 2FA code outstanding) also fails
        // IS_AUTHENTICATED_FULLY — but it is NOT a stale remember-me session and
        // must not be logged out. Defer to scheb's ExceptionListener (priority 2),
        // which answers with the JSON challenge signal or the /2fa redirect.
        if ($this->security->isGranted('IS_AUTHENTICATED_2FA_IN_PROGRESS')) {
            return;
        }

        // Case 2: User is authenticated via remember_me but not fully authenticated
        // This happens when IS_AUTHENTICATED_FULLY is required but user only has remember_me.
        // Log out programmatically (session + cookies cleared, redirect to login)
        // instead of redirecting through /logout: a browser following that
        // redirect from an address-bar navigation sends no same-origin fetch
        // metadata and would fail the logout CSRF check.
        if (!$this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            $response = $this->security->logout(false)
                ?? new RedirectResponse($this->router->generate('_login'));
            $exceptionEvent->setResponse($response);

            return;
        }

        // Case 3: User is fully authenticated but lacks required permissions
        // This is a real "forbidden" case (e.g., non-admin accessing /admin)

        // For API/JSON requests, return JSON response with consistent message
        $acceptHeader = (string) $request->headers->get('Accept', '');
        $contentType = (string) $request->headers->get('Content-Type', '');
        $pathInfo = $request->getPathInfo();

        // If explicitly requesting HTML, let Symfony render error403.html.twig
        $prefersHtml = str_contains($acceptHeader, 'text/html') && !str_contains($acceptHeader, self::MIME_TYPE_JSON);
        if ($prefersHtml) {
            return;
        }

        // Check if request expects JSON response (headers or API-like paths)
        $isJsonRequest = str_contains($acceptHeader, self::MIME_TYPE_JSON)
            || str_contains($contentType, self::MIME_TYPE_JSON)
            || 'XMLHttpRequest' === $request->headers->get('X-Requested-With')
            || str_starts_with($pathInfo, '/get')
            || str_starts_with($pathInfo, '/getAll')
            || str_ends_with($pathInfo, '/save')
            || str_ends_with($pathInfo, '/delete')
            || str_contains($pathInfo, '/api/');

        if ($isJsonRequest) {
            $response = new JsonResponse([
                'error' => 'Forbidden',
                'message' => 'You are not allowed to perform this action.',
            ], Response::HTTP_FORBIDDEN);
            $exceptionEvent->setResponse($response);
        }

        // For HTML requests, let Symfony's default exception handling render error403.html.twig
    }
}

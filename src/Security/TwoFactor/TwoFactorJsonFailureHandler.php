<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\TwoFactor;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Invoked when the code posted to /2fa_check is rejected. The SPA gets a JSON 401
 * and shows its own localized error; the no-JS fallback mirrors the scheb default
 * (error into the session, back to the /2fa form) — ADR-018 D2, increment 3.
 */
final readonly class TwoFactorJsonFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(private RouterInterface $router)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => false, 'error' => $exception->getMessageKey()], Response::HTTP_UNAUTHORIZED);
        }

        if ($request->hasSession()) {
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        }

        return new RedirectResponse($this->router->generate('2fa_login'));
    }
}

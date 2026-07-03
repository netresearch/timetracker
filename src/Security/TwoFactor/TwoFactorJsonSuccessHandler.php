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
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Invoked after a valid code on /2fa_check completes the login. Mirrors the shape
 * the SPA login form already expects from the password step ({ok, redirect}) and
 * honours a deep link saved before the login (TargetPathTrait — same behaviour as
 * the password-step authenticator); the no-JS fallback gets the plain redirect
 * (ADR-018 D2, increment 3).
 */
final class TwoFactorJsonSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $targetPath = $request->hasSession()
            ? $this->getTargetPath($request->getSession(), 'main')
            : null;
        $redirect = null !== $targetPath && '' !== $targetPath ? $targetPath : '/';

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true, 'redirect' => $redirect]);
        }

        return new RedirectResponse($redirect);
    }
}

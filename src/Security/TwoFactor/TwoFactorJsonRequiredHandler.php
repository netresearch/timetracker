<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\TwoFactor;

use Scheb\TwoFactorBundle\Security\Http\Authentication\AuthenticationRequiredHandlerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Invoked while a login is half-done (password ok, TOTP code outstanding) and the
 * client requests a protected resource. The SPA talks fetch/XHR and needs a JSON
 * signal it can branch on; the no-JS fallback is redirected to the scheb-rendered
 * /2fa form (ADR-018 D2, increment 3).
 */
final readonly class TwoFactorJsonRequiredHandler implements AuthenticationRequiredHandlerInterface
{
    public function __construct(private RouterInterface $router)
    {
    }

    public function onAuthenticationRequired(Request $request, TokenInterface $token): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => false, 'twoFactorRequired' => true], Response::HTTP_UNAUTHORIZED);
        }

        return new RedirectResponse($this->router->generate('2fa_login'));
    }
}

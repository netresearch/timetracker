<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\Webauthn;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Throwable;
use Webauthn\Bundle\Security\Handler\FailureHandler;

/**
 * Passkey LOGIN failed (ADR-018 D3). Returns the SPA's {ok:false, error} shape so
 * the login form can show an inline message and fall back to password sign-in.
 */
final class PasskeyLoginFailureHandler implements FailureHandler, AuthenticationFailureHandlerInterface
{
    public function onFailure(Request $request, ?Throwable $exception = null): JsonResponse
    {
        // Never echo the raw exception message — a WebAuthn verification failure can
        // carry internal detail (RP/origin mismatch, counter regression). The SPA
        // shows its own localized message; a generic marker is enough on the wire.
        return new JsonResponse(['ok' => false, 'error' => 'passkey_authentication_failed'], Response::HTTP_UNAUTHORIZED);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        return $this->onFailure($request, $exception);
    }
}

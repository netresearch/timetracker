<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\Webauthn;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Webauthn\Bundle\Security\Handler\SuccessHandler;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialOptions;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkey LOGIN succeeded (ADR-018 D3). Returns the SPA's usual login shape
 * ({ok, redirect}) instead of the bundle's default {status:ok}, honouring a deep
 * link saved before login (TargetPathTrait) — same behaviour as the password and
 * TOTP success handlers. A passkey with user-verification is inherently two
 * factors (device + biometric/PIN), so it is NOT sent through the TOTP challenge:
 * scheb matches its trigger token by exact class and a WebauthnToken is not in
 * the configured security_tokens list.
 */
final class PasskeyLoginSuccessHandler implements SuccessHandler, AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function onSuccess(
        Request $request,
        ?PublicKeyCredential $publicKeyCredential = null,
        ?PublicKeyCredentialOptions $publicKeyCredentialOptions = null,
        ?PublicKeyCredentialUserEntity $userEntity = null,
    ): JsonResponse {
        return $this->jsonRedirect($request);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): JsonResponse
    {
        return $this->jsonRedirect($request);
    }

    private function jsonRedirect(Request $request): JsonResponse
    {
        $targetPath = $request->hasSession()
            ? $this->getTargetPath($request->getSession(), 'main')
            : null;
        $redirect = null !== $targetPath && '' !== $targetPath ? $targetPath : '/';

        return new JsonResponse(['ok' => true, 'redirect' => $redirect]);
    }
}

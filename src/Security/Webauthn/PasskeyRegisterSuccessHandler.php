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
use Webauthn\Bundle\Security\Handler\SuccessHandler;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialOptions;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * A passkey was REGISTERED from the Settings Security section (ADR-018 D3). The
 * credential is already persisted by the ceremony controller; this returns the
 * settings-write shape ({success:true}) the SPA expects so it can refresh the
 * passkey list.
 */
final class PasskeyRegisterSuccessHandler implements SuccessHandler
{
    public function onSuccess(
        Request $request,
        ?PublicKeyCredential $publicKeyCredential = null,
        ?PublicKeyCredentialOptions $publicKeyCredentialOptions = null,
        ?PublicKeyCredentialUserEntity $userEntity = null,
    ): Response {
        return new JsonResponse(['success' => true], Response::HTTP_CREATED);
    }
}

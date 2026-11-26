<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\Webauthn;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Webauthn\Bundle\CredentialOptionsBuilder\PublicKeyCredentialRequestOptionsBuilder;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;

use function is_array;
use function json_decode;

/**
 * Passkey login options (/login/options) for the usernameless flow only
 * (ADR-018 D3; the SPA always posts {}). The anonymous endpoint refuses a
 * username: the bundle would look the account up, mint its passkey handle and
 * answer an existing account differently from an unknown one.
 */
#[AsDecorator('webauthn.controller.request.options_builder.firewall.main')]
final readonly class UsernamelessRequestOptionsBuilder implements PublicKeyCredentialRequestOptionsBuilder
{
    public function __construct(
        #[AutowireDecorated]
        private PublicKeyCredentialRequestOptionsBuilder $inner,
    ) {
    }

    public function getFromRequest(Request $request, ?PublicKeyCredentialUserEntity &$userEntity = null): PublicKeyCredentialRequestOptions
    {
        $body = json_decode($request->getContent(), true);
        if (is_array($body) && null !== ($body['username'] ?? null)) {
            throw new BadRequestHttpException('Passkey login is usernameless.');
        }

        return $this->inner->getFromRequest($request, $userEntity);
    }
}

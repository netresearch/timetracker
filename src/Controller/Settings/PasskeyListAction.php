<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\BaseController;
use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Model\JsonResponse;
use App\Repository\WebauthnCredentialRepository;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function rtrim;
use function strtr;

/**
 * The current user's registered passkeys, for the Settings Security list (ADR-018
 * D3). Only surfaces the local id (for removal) and a short base64url fingerprint
 * of the credential id — never the public key or trust path.
 */
final class PasskeyListAction extends BaseController
{
    public function __construct(private readonly WebauthnCredentialRepository $credentials)
    {
    }

    #[Route(path: '/settings/security/passkeys/list', name: 'settings_passkey_list', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        $handle = $user->getWebauthnUserHandle();
        $passkeys = null === $handle ? [] : $this->credentials->findByUserHandle($handle);

        return new JsonResponse([
            'passkeys' => array_map(
                static fn (WebauthnCredential $credential): array => [
                    'id' => $credential->getId(),
                    // A short, non-reversible display handle (base64url of the raw
                    // credential id) so the user can tell two passkeys apart.
                    'fingerprint' => rtrim(strtr(base64_encode($credential->publicKeyCredentialId), '+/', '-_'), '='),
                    'transports' => $credential->transports,
                ],
                $passkeys,
            ),
        ]);
    }
}

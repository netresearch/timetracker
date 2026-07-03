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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Remove one of the current user's passkeys (ADR-018 D3). Ownership is enforced
 * by the user-handle match — a user can only delete a credential bound to their
 * own handle, never another account's by guessing an id.
 */
final class PasskeyDeleteAction extends BaseController
{
    public function __construct(private readonly WebauthnCredentialRepository $credentials)
    {
    }

    #[Route(path: '/settings/security/passkeys/delete', name: 'settings_passkey_delete', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $id = (int) $request->getPayload()->get('id', 0);
        $handle = $user->getWebauthnUserHandle();

        $credential = $id > 0 ? $this->credentials->find($id) : null;
        if (!$credential instanceof WebauthnCredential || null === $handle || $credential->userHandle !== $handle) {
            $response = new JsonResponse(['success' => false, 'message' => $this->translate('That passkey could not be found.')]);
            $response->setStatusCode(Response::HTTP_NOT_FOUND);

            return $response;
        }

        $manager = $this->doctrineRegistry->getManager();
        $manager->remove($credential);
        $manager->flush();

        return new JsonResponse(['success' => true]);
    }
}

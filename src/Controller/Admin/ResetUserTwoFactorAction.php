<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\IdDto;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Repository\WebauthnCredentialRepository;
use App\Response\Error;
use App\Service\Security\TwoFactorEnrollmentService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin break-glass: clear another user's SECOND FACTORS (ADR-018) — the TOTP
 * secret, any outstanding backup codes, AND every registered passkey. This is
 * the recovery path for a user who has lost their authenticator/passkey device;
 * it drops them back to password-only sign-in, from where they can re-enrol.
 *
 * ROLE_ADMIN only. Unlike the self-service disable (D4), no second-factor re-auth
 * is demanded — the whole point is that the target no longer has the factor. It
 * mirrors the other destructive admin endpoints (DeleteUser): admin authority is
 * the gate.
 */
final class ResetUserTwoFactorAction extends BaseController
{
    public function __construct(
        private readonly TwoFactorEnrollmentService $enrollment,
        private readonly WebauthnCredentialRepository $credentials,
    ) {
    }

    #[Route(path: '/user/reset-2fa', name: 'resetUserTwoFactor_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        #[MapRequestPayload]
        IdDto $idDto,
        #[CurrentUser]
        User $currentUser,
    ): JsonResponse|Error {
        $user = $this->doctrineRegistry->getRepository(User::class)->find($idDto->id);
        if (!$user instanceof User) {
            return new Error($this->translate('No entry for id.'), Response::HTTP_NOT_FOUND);
        }

        // No self-reset via the break-glass panel: an admin turning off their OWN
        // 2FA here would sidestep the self-service re-auth (ADR-018 D4), so a
        // hijacked/unattended admin session can't strip its own protection. The
        // admin disables their own 2FA through Settings, where re-auth is enforced.
        if ($user->getId() === $currentUser->getId()) {
            return new Error(
                $this->translate('You cannot reset your own two-factor authentication. Use the security settings instead.'),
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Unconditional clear (disable() is idempotent): always drop the secret AND
        // the backup codes, even in an inconsistent state where only one is set.
        $this->enrollment->disable($user);

        // Passkeys are second factors too — remove them all. The handle itself
        // stays on the user (it is stable and non-enumerable; re-registration
        // reuses it).
        $handle = $user->getWebauthnUserHandle();
        if (null !== $handle && '' !== $handle) {
            $this->credentials->deleteByUserHandle($handle);
        }

        $manager = $this->doctrineRegistry->getManager();
        $manager->persist($user);
        $manager->flush();

        return new JsonResponse(['success' => true]);
    }
}

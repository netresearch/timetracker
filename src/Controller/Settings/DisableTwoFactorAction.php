<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\BaseController;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Service\Security\TwoFactorEnrollmentService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Turn TOTP two-factor off (ADR-018 D2/D4): clears the secret and any outstanding
 * backup codes — but only after a fresh re-authentication with the second factor.
 *
 * The re-auth (a current TOTP code or a backup code) proves live possession, so a
 * merely-open session — e.g. an unlocked, unattended browser — can't silently
 * strip the account's protection. It is uniform for local and LDAP accounts: both
 * prove possession of the factor, not a password (the D4 hardening item).
 */
final class DisableTwoFactorAction extends BaseController
{
    public function __construct(private readonly TwoFactorEnrollmentService $enrollment)
    {
    }

    #[Route(path: '/settings/2fa/disable', name: 'settings_2fa_disable', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $user,
    ): JsonResponse {
        // Already off: idempotent success, no code needed.
        if (!$user->isTotpAuthenticationEnabled()) {
            return new JsonResponse(['enabled' => false]);
        }

        $code = (string) $request->getPayload()->get('code', '');
        if (!$this->enrollment->verifyUserCode($user, $code)) {
            // 422 with no prose: the SPA renders a localized message (the app's
            // i18n is frontend-side, Paraglide), so no untranslated string leaks.
            $response = new JsonResponse(['enabled' => true]);
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

            return $response;
        }

        $this->enrollment->disable($user);

        $manager = $this->managerRegistry->getManager();
        $manager->persist($user);
        $manager->flush();

        return new JsonResponse(['enabled' => false]);
    }
}

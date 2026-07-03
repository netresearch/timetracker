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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Begin TOTP enrolment (ADR-018 D2): generate a fresh secret, stash it in the
 * session as pending (not yet on the user), and return the otpauth URI so the
 * SPA can render a QR code. The secret is only persisted once the user confirms
 * a valid code (see ConfirmTotpEnrollmentAction).
 */
final class StartTotpEnrollmentAction extends BaseController
{
    public const string PENDING_SECRET_KEY = '2fa_pending_totp_secret';

    public function __construct(private readonly TwoFactorEnrollmentService $enrollment)
    {
    }

    #[Route(path: '/settings/2fa/totp/start', name: 'settings_2fa_totp_start', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $user,
    ): JsonResponse {
        $secret = $this->enrollment->generateSecret();
        $request->getSession()->set(self::PENDING_SECRET_KEY, $secret);

        return new JsonResponse([
            'provisioningUri' => $this->enrollment->provisioningUri($user, $secret),
            // Shown for manual entry when a QR code can't be scanned.
            'secret' => $secret,
        ]);
    }
}

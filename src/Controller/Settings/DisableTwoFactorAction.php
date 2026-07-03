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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Turn TOTP two-factor off (ADR-018 D2): clears the secret and any outstanding
 * backup codes. Requires a fully-authenticated session (which, for a 2FA-enabled
 * account, already means the current 2FA step was passed this session).
 *
 * NOTE: a fresh re-authentication challenge on disable is a D4 hardening item.
 */
final class DisableTwoFactorAction extends BaseController
{
    public function __construct(private readonly TwoFactorEnrollmentService $enrollment)
    {
    }

    #[Route(path: '/settings/2fa/disable', name: 'settings_2fa_disable', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        #[CurrentUser]
        User $user,
    ): JsonResponse {
        $this->enrollment->disable($user);

        $manager = $this->managerRegistry->getManager();
        $manager->persist($user);
        $manager->flush();

        return new JsonResponse(['enabled' => false]);
    }
}

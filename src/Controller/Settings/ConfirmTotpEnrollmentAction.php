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

use function is_string;

/**
 * Confirm TOTP enrolment (ADR-018 D2): verify a first code against the pending
 * secret from the session, then persist the encrypted secret and issue the
 * one-time backup codes (returned once). A wrong code leaves 2FA off.
 */
final class ConfirmTotpEnrollmentAction extends BaseController
{
    public function __construct(private readonly TwoFactorEnrollmentService $enrollment)
    {
    }

    #[Route(path: '/settings/2fa/totp/confirm', name: 'settings_2fa_totp_confirm', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $user,
    ): JsonResponse {
        $session = $request->getSession();
        $secret = $session->get(StartTotpEnrollmentAction::PENDING_SECRET_KEY);
        if (!is_string($secret) || '' === $secret) {
            return $this->jsonError('No enrolment is in progress. Start again.', Response::HTTP_BAD_REQUEST);
        }

        // getPayload() reads the body whether the SPA sends form-encoded (postForm)
        // or JSON (postJson) — $request->request alone is empty for a JSON body.
        $code = (string) $request->getPayload()->get('code', '');
        $backupCodes = $this->enrollment->confirm($user, $secret, $code);
        if (null === $backupCodes) {
            return $this->jsonError('That code is not valid. Check your authenticator and try again.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $manager = $this->managerRegistry->getManager();
        $manager->persist($user);
        $manager->flush();
        $session->remove(StartTotpEnrollmentAction::PENDING_SECRET_KEY);

        // The backup codes are shown to the user exactly once here — only their
        // hashes are stored, so they cannot be recovered later.
        return new JsonResponse([
            'enabled' => true,
            'backupCodes' => $backupCodes,
        ]);
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        $response = new JsonResponse([
            'enabled' => false,
            'message' => $this->translator->trans($message),
        ]);
        $response->setStatusCode($status);

        return $response;
    }
}

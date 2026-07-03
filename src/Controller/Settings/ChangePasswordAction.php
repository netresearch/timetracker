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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function mb_strlen;

/**
 * Self-service password change (ADR-018 D2).
 *
 * Available ONLY to local accounts: an LDAP-authenticated user has no local
 * password and must not be able to set one here (that would silently convert the
 * account to local — an admin/CLI-only decision). The current password is
 * re-verified before the change; this is a change, not a reset (no mailer).
 */
final class ChangePasswordAction extends BaseController
{
    /** Minimal length floor for a new password (matches the admin Users form). */
    private const int MIN_LENGTH = 8;

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    #[Route(path: '/settings/password', name: 'settings_password_change', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $user,
    ): JsonResponse {
        if (!$user->isLocalAccount()) {
            // LDAP account: no local password to change, and self-service must not
            // create one. Directory users change their password in the directory.
            return $this->error('Your password is managed by the directory (LDAP) and cannot be changed here.', Response::HTTP_FORBIDDEN);
        }

        $payload = $request->getPayload();
        $current = (string) $payload->get('currentPassword', '');
        $new = (string) $payload->get('newPassword', '');

        if (!$this->passwordHasher->isPasswordValid($user, $current)) {
            return $this->error('Your current password is incorrect.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($new) < self::MIN_LENGTH) {
            return $this->error('The new password must be at least 8 characters.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $new));

        $manager = $this->managerRegistry->getManager();
        $manager->persist($user);
        $manager->flush();

        return new JsonResponse(['success' => true]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        $response = new JsonResponse(['success' => false, 'message' => $this->translate($message)]);
        $response->setStatusCode($status);

        return $response;
    }
}

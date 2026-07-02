<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Security\TokenEncryptionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decrypts a user's stored TOTP secret on load (ADR-018 D2).
 *
 * The `users.totp_secret` column holds AES-256-GCM ciphertext; scheb reads the
 * plaintext via User::getTotpAuthenticationConfiguration(). This listener bridges
 * the two by decrypting into the entity's transient plain field right after
 * hydration, keeping the plaintext off the database and out of every DTO.
 *
 * A decryption failure (e.g. after an encryption-key rotation) is logged and
 * leaves the plain secret null rather than crashing every request that loads the
 * user; the account then presents as "TOTP not configured" until re-enrolled.
 */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: User::class)]
final readonly class UserTwoFactorSubscriber
{
    public function __construct(
        private TokenEncryptionService $tokenEncryptionService,
        private LoggerInterface $logger,
    ) {
    }

    public function postLoad(User $user): void
    {
        $encrypted = $user->getTotpSecret();
        if (null === $encrypted || '' === $encrypted) {
            return;
        }

        try {
            $user->setTotpSecretPlain($this->tokenEncryptionService->decryptToken($encrypted));
        } catch (Throwable $throwable) {
            $user->setTotpSecretPlain(null);
            $this->logger->error('Failed to decrypt a user TOTP secret; treating 2FA as not configured.', [
                'user_id' => $user->getId(),
                'error_type' => $throwable::class,
            ]);
        }
    }
}

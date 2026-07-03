<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use App\Repository\WebauthnCredentialRepository;

/**
 * Answers "does this user have a working second factor?" (ADR-018) — either a
 * confirmed TOTP secret or at least one registered passkey. Backs the org-wide
 * mandatory-2FA gate and the SPA's enrolment state.
 */
final readonly class TwoFactorStatusService
{
    public function __construct(private WebauthnCredentialRepository $credentials)
    {
    }

    public function hasTwoFactor(User $user): bool
    {
        if ($user->isTotpAuthenticationEnabled()) {
            return true;
        }

        // A passkey counts as a second factor (possession + user verification). The
        // handle is minted only when a passkey ceremony starts, so a null handle
        // reliably means "no passkeys" — skip the query.
        $handle = $user->getWebauthnUserHandle();
        if (null === $handle || '' === $handle) {
            return false;
        }

        return $this->credentials->countByUserHandle($handle) > 0;
    }
}

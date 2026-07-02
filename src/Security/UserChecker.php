<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses authentication for deactivated accounts. Wired as the `main`
 * firewall's user_checker (config/packages/security.yaml).
 *
 * The check runs in checkPostAuth (AuthenticationSuccessEvent), i.e. AFTER the
 * credential check, so the distinct "deactivated" message is only ever revealed
 * to someone who already presented valid credentials — a wrong password yields
 * the generic bad-credentials error, so the endpoint can't be used to probe
 * which usernames exist and are deactivated (this matters for local password
 * accounts, whose user row is resolved before the credential check). It still
 * enforces on remember-me: AuthenticationSuccessEvent fires for token-based
 * auth too, whereas checkPreAuth is skipped for PreAuthenticatedUserBadge
 * passports.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        // Intentionally empty: the deactivation check runs in checkPostAuth (after
        // the credential check) to avoid disclosing account status pre-auth. See
        // the class docblock.
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && !$user->getActive()) {
            throw new CustomUserMessageAccountStatusException('This account has been deactivated.');
        }
    }
}

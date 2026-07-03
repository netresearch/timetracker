<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Traits;

use App\Entity\User;

/**
 * Shared helper for the local-password security tests (ADR-018 D1/D2): assign a
 * stored hash to a user via the entity manager rather than raw SQL, so the login
 * request's identity-map read stays consistent (a raw UPDATE leaves a stale
 * cached entity behind — the trap ChangePasswordTest first hit).
 */
trait LocalPasswordTestTrait
{
    private function setStoredPassword(int $userId, ?string $hash): void
    {
        self::assertNotNull($this->serviceContainer);
        $manager = $this->serviceContainer->get('doctrine')->getManager();
        $user = $manager->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        $user->setPassword($hash);
        $manager->flush();
    }
}

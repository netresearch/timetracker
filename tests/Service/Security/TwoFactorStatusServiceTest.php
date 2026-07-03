<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Security;

use App\Entity\User;
use App\Repository\WebauthnCredentialRepository;
use App\Service\Security\TwoFactorStatusService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TwoFactorStatusService::class)]
final class TwoFactorStatusServiceTest extends TestCase
{
    public function testTotpCountsAsASecondFactorWithoutQueryingPasskeys(): void
    {
        $credentials = self::createMock(WebauthnCredentialRepository::class);
        $credentials->expects(self::never())->method('countByUserHandle');

        $user = new User();
        $user->setTotpSecret('ENC(x)', 'x');

        self::assertTrue(new TwoFactorStatusService($credentials)->hasTwoFactor($user));
    }

    public function testAPasskeyCountsAsASecondFactor(): void
    {
        $credentials = self::createStub(WebauthnCredentialRepository::class);
        $credentials->method('countByUserHandle')->willReturn(1);

        $user = new User();
        $user->setWebauthnUserHandle('11111111-1111-1111-1111-111111111111');

        self::assertTrue(new TwoFactorStatusService($credentials)->hasTwoFactor($user));
    }

    public function testNoTotpAndNoHandleIsNoSecondFactorAndSkipsTheQuery(): void
    {
        $credentials = self::createMock(WebauthnCredentialRepository::class);
        $credentials->expects(self::never())->method('countByUserHandle');

        // Fresh user: no TOTP, handle never minted.
        self::assertFalse(new TwoFactorStatusService($credentials)->hasTwoFactor(new User()));
    }

    public function testAHandleWithZeroPasskeysIsNoSecondFactor(): void
    {
        $credentials = self::createStub(WebauthnCredentialRepository::class);
        $credentials->method('countByUserHandle')->willReturn(0);

        $user = new User();
        $user->setWebauthnUserHandle('22222222-2222-2222-2222-222222222222');

        self::assertFalse(new TwoFactorStatusService($credentials)->hasTwoFactor($user));
    }
}

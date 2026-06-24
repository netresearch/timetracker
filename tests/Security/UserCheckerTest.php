<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * @internal
 */
final class UserCheckerTest extends TestCase
{
    public function testActiveUserPassesPreAuth(): void
    {
        $this->expectNotToPerformAssertions();

        new UserChecker()->checkPreAuth(new User()->setActive(true));
    }

    public function testDeactivatedUserIsRejected(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);

        new UserChecker()->checkPreAuth(new User()->setActive(false));
    }

    public function testNonAppUserIsIgnored(): void
    {
        // The check only applies to our User entity; any other UserInterface passes.
        $this->expectNotToPerformAssertions();

        new UserChecker()->checkPreAuth(new InMemoryUser('x', null));
    }
}

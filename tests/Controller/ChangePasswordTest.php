<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\LocalPasswordTestTrait;

use function is_string;
use function password_hash;
use function password_verify;

use const PASSWORD_DEFAULT;

/**
 * Self-service password change (ADR-018 D2): only local accounts, current
 * password re-verified, minimum length enforced.
 *
 * @internal
 *
 * @coversNothing
 */
final class ChangePasswordTest extends AbstractWebTestCase
{
    use LocalPasswordTestTrait;

    public function testLdapAccountCannotChangePassword(): void
    {
        $this->setStoredPassword(1, null); // LDAP account: no local password
        $this->logInSession('unittest');

        $this->post(['currentPassword' => 'anything', 'newPassword' => 'newpass12']);

        $this->assertStatusCode(403);
    }

    public function testLocalAccountChangesPasswordWithCorrectCurrent(): void
    {
        $this->setStoredPassword(1, password_hash('oldpass12', PASSWORD_DEFAULT));
        $this->logInSession('unittest');

        $this->post(['currentPassword' => 'oldpass12', 'newPassword' => 'brandnew34']);

        $this->assertStatusCode(200);
        $stored = $this->storedPassword(1);
        self::assertNotNull($stored);
        self::assertTrue(password_verify('brandnew34', $stored), 'the new password is stored');
        self::assertFalse(password_verify('oldpass12', $stored), 'the old password no longer works');
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $this->setStoredPassword(1, password_hash('oldpass12', PASSWORD_DEFAULT));
        $this->logInSession('unittest');

        $this->post(['currentPassword' => 'WRONG', 'newPassword' => 'brandnew34']);

        $this->assertStatusCode(422);
    }

    public function testTooShortNewPasswordIsRejected(): void
    {
        $this->setStoredPassword(1, password_hash('oldpass12', PASSWORD_DEFAULT));
        $this->logInSession('unittest');

        $this->post(['currentPassword' => 'oldpass12', 'newPassword' => 'short']);

        $this->assertStatusCode(422);
    }

    /**
     * @param array<string, string> $body
     */
    private function post(array $body): void
    {
        $this->client->request(Request::METHOD_POST, '/settings/password', $body, [], ['HTTP_ACCEPT' => 'application/json']);
    }

    private function storedPassword(int $userId): ?string
    {
        $connection = $this->connection;
        self::assertNotNull($connection);
        $value = $connection->fetchOne('SELECT password FROM users WHERE id = ?', [$userId]);

        return is_string($value) ? $value : null;
    }
}

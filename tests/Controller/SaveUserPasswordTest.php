<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function is_string;
use function password_verify;

/**
 * Covers the admin explicit authentication-source block on /user/save (ADR-018 D1):
 * 'local' sets/keeps a password, 'ldap' clears it.
 *
 * @internal
 *
 * @coversNothing
 */
final class SaveUserPasswordTest extends AbstractWebTestCase
{
    public function testSettingPasswordStoresAHashAndMakesAccountLocal(): void
    {
        $id = $this->saveUser('pwuser1', ['authSource' => 'local', 'password' => 'sup3rsecret123']);

        $stored = $this->storedPassword($id);
        self::assertNotNull($stored, 'password column must be populated');
        self::assertNotSame('sup3rsecret123', $stored, 'password must not be stored in plain text');
        self::assertTrue(password_verify('sup3rsecret123', $stored), 'stored value must be a valid hash of the password');
    }

    public function testChoosingLdapRevertsAccountToDirectory(): void
    {
        $id = $this->saveUser('pwuser2', ['authSource' => 'local', 'password' => 'sup3rsecret123']);
        self::assertNotNull($this->storedPassword($id));

        // Re-save with authSource=ldap → the local hash is removed (directory account).
        $this->saveUser('pwuser2', ['id' => $id, 'authSource' => 'ldap']);

        self::assertNull($this->storedPassword($id), 'switching to LDAP must null the hash');
    }

    public function testLocalWithoutNewPasswordLeavesExistingHashUnchanged(): void
    {
        $id = $this->saveUser('pwuser3', ['authSource' => 'local', 'password' => 'sup3rsecret123']);
        $before = $this->storedPassword($id);
        self::assertNotNull($before);

        // Editing a local user without a new password must not touch the stored hash.
        $this->saveUser('pwuser3', ['id' => $id, 'type' => 'PL', 'authSource' => 'local']);

        self::assertSame($before, $this->storedPassword($id));
    }

    public function testTooShortPasswordIsRejected(): void
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_POST, '/user/save', [
            'username' => 'pwuser4',
            'abbr' => 'PW4',
            'teams' => ['1'],
            'locale' => 'de',
            'type' => 'DEV',
            'authSource' => 'local',
            'password' => 'short',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(422);
    }

    public function testPasswordUnderLdapIsRejected(): void
    {
        // Contradictory intent (a password while choosing the directory) is rejected.
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_POST, '/user/save', [
            'username' => 'pwuser5',
            'abbr' => 'PW5',
            'teams' => ['1'],
            'locale' => 'de',
            'type' => 'DEV',
            'authSource' => 'ldap',
            'password' => 'sup3rsecret123',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(422);
    }

    public function testNewLocalAccountWithoutPasswordIsRejected(): void
    {
        // An admin picking 'local' without ever setting a password must not be
        // silently downgraded to LDAP — the account has no hash to fall back on.
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_POST, '/user/save', [
            'username' => 'pwuser6',
            'abbr' => 'PW6',
            'teams' => ['1'],
            'locale' => 'de',
            'type' => 'DEV',
            'authSource' => 'local',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(422);
    }

    public function testLegacyEditWithoutAuthSourceKeepsLocalPassword(): void
    {
        // A client that predates the auth-source control posts a bare edit (no
        // authSource field). This must NOT clear an existing local hash — a silent
        // downgrade to the directory would be data loss.
        $id = $this->saveUser('pwuser7', ['authSource' => 'local', 'password' => 'sup3rsecret123']);
        $before = $this->storedPassword($id);
        self::assertNotNull($before);

        // No authSource, no password — just a routine field edit.
        $this->saveUser('pwuser7', ['id' => $id, 'type' => 'PL']);

        self::assertSame($before, $this->storedPassword($id), 'a legacy bare edit must not touch the hash');
    }

    /**
     * POST /user/save with sane defaults plus the given overrides; returns the id.
     *
     * @param array<string, mixed> $overrides
     */
    private function saveUser(string $username, array $overrides): int
    {
        $this->logInSession('unittest');
        $payload = array_merge([
            'username' => $username,
            'abbr' => strtoupper(substr($username, -3)),
            'teams' => ['1'],
            'locale' => 'de',
            'type' => 'DEV',
        ], $overrides);

        $this->client->request(Request::METHOD_POST, '/user/save', $payload, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);
        self::assertIsInt($data[0]);

        return $data[0];
    }

    private function storedPassword(int $id): ?string
    {
        $connection = $this->connection;
        self::assertNotNull($connection);
        $value = $connection->fetchOne('SELECT password FROM users WHERE id = ?', [$id]);

        return is_string($value) ? $value : null;
    }
}

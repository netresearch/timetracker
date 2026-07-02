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
 * Covers the admin "set / clear local password" block on /user/save (ADR-018 D1).
 *
 * @internal
 *
 * @coversNothing
 */
final class SaveUserPasswordTest extends AbstractWebTestCase
{
    public function testSettingPasswordStoresAHashAndMakesAccountLocal(): void
    {
        $id = $this->saveUser('pwuser1', ['password' => 'sup3rsecret123']);

        $stored = $this->storedPassword($id);
        self::assertNotNull($stored, 'password column must be populated');
        self::assertNotSame('sup3rsecret123', $stored, 'password must not be stored in plain text');
        self::assertTrue(password_verify('sup3rsecret123', $stored), 'stored value must be a valid hash of the password');
    }

    public function testClearingPasswordRevertsAccountToLdap(): void
    {
        $id = $this->saveUser('pwuser2', ['password' => 'sup3rsecret123']);
        self::assertNotNull($this->storedPassword($id));

        // Re-save with clearPassword set → the local hash is removed (LDAP account).
        $this->saveUser('pwuser2', ['id' => $id, 'clearPassword' => '1']);

        self::assertNull($this->storedPassword($id), 'clearPassword must null the hash');
    }

    public function testEmptyPasswordLeavesExistingHashUnchanged(): void
    {
        $id = $this->saveUser('pwuser3', ['password' => 'sup3rsecret123']);
        $before = $this->storedPassword($id);
        self::assertNotNull($before);

        // A normal edit (no password field) must not touch the stored hash.
        $this->saveUser('pwuser3', ['id' => $id, 'type' => 'PL']);

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
            'password' => 'short',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(422);
    }

    public function testSettingAndClearingTogetherIsRejected(): void
    {
        // Contradictory intent must be rejected, not silently resolved.
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_POST, '/user/save', [
            'username' => 'pwuser5',
            'abbr' => 'PW5',
            'teams' => ['1'],
            'locale' => 'de',
            'type' => 'DEV',
            'password' => 'sup3rsecret123',
            'clearPassword' => '1',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(422);
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

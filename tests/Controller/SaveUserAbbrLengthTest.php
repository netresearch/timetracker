<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

use function assert;
use function is_string;

/**
 * Regression tests for https://github.com/netresearch/timetracker/issues/35.
 *
 * The user abbreviation column is char(3), so 1 to 3 characters must be
 * accepted; only empty and longer-than-3 values are rejected.
 *
 * @internal
 *
 * @coversNothing
 */
final class SaveUserAbbrLengthTest extends AbstractWebTestCase
{
    public function testSaveUserAcceptsOneLetterAbbr(): void
    {
        $this->assertUserSaved('abbruser1', 'Q');
    }

    public function testSaveUserAcceptsTwoLetterAbbr(): void
    {
        $this->assertUserSaved('abbruser2', 'QW');
    }

    public function testSaveUserAcceptsThreeLetterAbbr(): void
    {
        $this->assertUserSaved('abbruser3', 'QWZ');
    }

    public function testSaveUserRejectsTooLongAbbr(): void
    {
        $this->assertUserRejected('abbruser4', 'QWXZ');
    }

    public function testSaveUserRejectsEmptyAbbr(): void
    {
        $this->assertUserRejected('abbruser5', '');
    }

    /**
     * @return array<mixed>
     */
    private function saveUser(string $username, string $abbr): array
    {
        $this->logInSession('unittest');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/user/save', [
            'username' => $username,
            'abbr' => $abbr,
            'teams' => ['1'],
            'locale' => 'de',
            'type' => 'DEV',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);

        return $data;
    }

    private function assertUserSaved(string $username, string $abbr): void
    {
        $data = $this->saveUser($username, $abbr);

        $this->assertStatusCode(200);
        // Response payload is [id, username, abbr, type]
        self::assertSame($username, $data[1]);
        self::assertSame($abbr, $data[2]);
    }

    private function assertUserRejected(string $username, string $abbr): void
    {
        $data = $this->saveUser($username, $abbr);

        $this->assertStatusCode(422);
        self::assertArrayHasKey('message', $data);
        assert(is_string($data['message']));
        self::assertStringContainsString('abbreviation', $data['message']);
    }
}

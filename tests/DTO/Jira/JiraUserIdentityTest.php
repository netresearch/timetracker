<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\DTO\Jira;

use App\DTO\Jira\JiraUserIdentity;
use App\DTO\Jira\JiraWorkLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JiraUserIdentity.
 *
 * @internal
 */
#[CoversClass(JiraUserIdentity::class)]
final class JiraUserIdentityTest extends TestCase
{
    public function testFromApiResponse(): void
    {
        $identity = JiraUserIdentity::fromApiResponse((object) [
            'accountId' => 'abc-123',
            'name' => 'jdoe',
            'emailAddress' => 'JDoe@Example.com',
        ]);

        self::assertSame('abc-123', $identity->accountId);
        self::assertSame('jdoe', $identity->name);
        self::assertSame('JDoe@Example.com', $identity->email);
    }

    public function testMatchesByAccountId(): void
    {
        $identity = new JiraUserIdentity(accountId: 'abc-123');
        $workLog = new JiraWorkLog(id: 1, authorAccountId: 'abc-123');

        self::assertTrue($identity->matchesWorklogAuthor($workLog));
    }

    public function testMatchesByEmailCaseInsensitive(): void
    {
        $identity = new JiraUserIdentity(email: 'jdoe@example.com');
        $workLog = new JiraWorkLog(id: 1, authorEmail: 'JDOE@example.com');

        self::assertTrue($identity->matchesWorklogAuthor($workLog));
    }

    public function testNoMatchWhenNothingOverlaps(): void
    {
        $identity = new JiraUserIdentity(accountId: 'abc-123', name: 'jdoe');
        $workLog = new JiraWorkLog(id: 1, authorAccountId: 'other', authorName: 'someone');

        self::assertFalse($identity->matchesWorklogAuthor($workLog));
    }

    public function testNullSidesNeverMatch(): void
    {
        self::assertFalse(new JiraUserIdentity()->matchesWorklogAuthor(new JiraWorkLog(id: 1)));
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\ValueObject;

use App\ValueObject\ApiScope;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ApiScopeTest extends TestCase
{
    public function testAllIncludesWildcardAndResourceActions(): void
    {
        $all = ApiScope::all();

        self::assertContains('*', $all);
        self::assertContains('entries:read', $all);
        self::assertContains('projects:write', $all);
        // 12 resources x 2 actions + the wildcard.
        self::assertCount(25, $all);
    }

    public function testIsValid(): void
    {
        self::assertTrue(ApiScope::isValid('entries:write'));
        self::assertTrue(ApiScope::isValid('*'));
        self::assertFalse(ApiScope::isValid('entries:delete'));
        self::assertFalse(ApiScope::isValid('bogus'));
        self::assertFalse(ApiScope::isValid(''));
    }

    public function testGrantsHonoursWildcardAndExactMatch(): void
    {
        self::assertTrue(ApiScope::grants(['*'], 'users:write'));
        self::assertTrue(ApiScope::grants(['entries:read', 'projects:read'], 'projects:read'));
        self::assertFalse(ApiScope::grants(['entries:read'], 'entries:write'));
        self::assertFalse(ApiScope::grants([], 'entries:read'));
    }
}

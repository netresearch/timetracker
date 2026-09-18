<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Enum;

use App\Enum\EntrySource;
use PHPUnit\Framework\TestCase;

final class EntrySourceTest extends TestCase
{
    public function testCasesAndLabels(): void
    {
        self::assertSame('human', EntrySource::HUMAN->value);
        self::assertSame('agent', EntrySource::AGENT->value);
        self::assertSame('Human', EntrySource::HUMAN->label());
    }

    public function testIsValidRejectsUnknown(): void
    {
        self::assertTrue(EntrySource::isValid('agent'));
        self::assertFalse(EntrySource::isValid('robot'));
    }
}

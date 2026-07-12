<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\DTO\Personio;

use App\DTO\Personio\AbsenceType;
use PHPUnit\Framework\TestCase;

use function json_decode;

/**
 * @internal
 *
 * @covers \App\DTO\Personio\AbsenceType
 */
final class AbsenceTypeTest extends TestCase
{
    public function testParsesDayBasedVacationType(): void
    {
        $type = AbsenceType::fromApiResponse($this->decode(<<<'JSON'
            {"id": "type-vac", "name": "Urlaub", "category": "PAID_VACATION", "unit": "DAY"}
            JSON));

        self::assertSame('type-vac', $type->id);
        self::assertSame('Urlaub', $type->name);
        self::assertSame('PAID_VACATION', $type->category);
        self::assertSame('DAY', $type->unit);
        self::assertTrue($type->isDayBased());
        self::assertSame('urlaub', $type->normalizedName());
    }

    public function testHourBasedTypeIsNotDayBased(): void
    {
        $type = AbsenceType::fromApiResponse($this->decode(<<<'JSON'
            {"id": "type-doc", "name": "Doctor Visit", "category": "OTHER", "unit": "HOUR"}
            JSON));

        self::assertFalse($type->isDayBased());
    }

    public function testMissingUnitDefaultsToDayBased(): void
    {
        $type = AbsenceType::fromApiResponse($this->decode(<<<'JSON'
            {"id": "type-x", "name": "Krank"}
            JSON));

        self::assertNull($type->unit);
        self::assertTrue($type->isDayBased());
        self::assertNull($type->category);
    }

    private function decode(string $json): object
    {
        $decoded = json_decode($json, false);
        self::assertIsObject($decoded);

        return $decoded;
    }
}

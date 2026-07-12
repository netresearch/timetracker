<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\DTO\Personio;

use App\DTO\Personio\AbsencePeriod;
use PHPUnit\Framework\TestCase;

use function json_decode;

/**
 * @internal
 *
 * @covers \App\DTO\Personio\AbsencePeriod
 */
final class AbsencePeriodTest extends TestCase
{
    public function testParsesFullMultiDayVacation(): void
    {
        $period = AbsencePeriod::fromApiResponse($this->decode(<<<'JSON'
            {
                "id": "abc-123",
                "person": {"id": "42"},
                "absence_type": {"id": "type-vac"},
                "starts_from": {"date_time": "2026-07-06T00:00:00.000", "type": null},
                "ends_at": {"date_time": "2026-07-10T00:00:00.000", "type": null},
                "approval": {"status": "APPROVED"},
                "comment": "Summer break"
            }
            JSON));

        self::assertSame('abc-123', $period->id);
        self::assertSame('42', $period->personId);
        self::assertSame('type-vac', $period->absenceTypeId);
        self::assertSame('2026-07-06T00:00:00.000', $period->startDateTime);
        self::assertSame('2026-07-10T00:00:00.000', $period->endDateTime);
        self::assertSame('APPROVED', $period->approvalStatus);
        self::assertSame('Summer break', $period->comment);
        self::assertFalse($period->startsHalf());
        self::assertFalse($period->endsHalf());
    }

    public function testCollapsesHalfDayBoundaryEnumToABoolean(): void
    {
        $period = AbsencePeriod::fromApiResponse($this->decode(<<<'JSON'
            {
                "id": "abc-124",
                "person": {"id": "42"},
                "absence_type": {"id": "type-vac"},
                "starts_from": {"date_time": "2026-07-06T00:00:00.000", "type": "SECOND_HALF"},
                "ends_at": {"date_time": "2026-07-06T00:00:00.000", "type": "SECOND_HALF"},
                "approval": {"status": "PENDING"},
                "comment": null
            }
            JSON));

        self::assertSame('SECOND_HALF', $period->startHalf);
        self::assertTrue($period->startsHalf());
        self::assertTrue($period->endsHalf());
        self::assertNull($period->comment);
    }

    public function testOpenEndedAbsenceHasNullEnd(): void
    {
        $period = AbsencePeriod::fromApiResponse($this->decode(<<<'JSON'
            {
                "id": "abc-125",
                "person": {"id": "7"},
                "absence_type": {"id": "type-sick"},
                "starts_from": {"date_time": "2026-07-06T00:00:00.000", "type": null},
                "ends_at": null,
                "approval": {"status": "APPROVED"}
            }
            JSON));

        self::assertNull($period->endDateTime);
        self::assertNull($period->endHalf);
        self::assertFalse($period->endsHalf());
        self::assertNull($period->comment);
    }

    private function decode(string $json): object
    {
        $decoded = json_decode($json, false);
        self::assertIsObject($decoded);

        return $decoded;
    }
}

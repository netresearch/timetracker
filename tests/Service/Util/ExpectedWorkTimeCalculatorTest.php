<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Util;

use App\Entity\Contract;
use App\Service\Util\ContractHoursResolver;
use App\Service\Util\ExpectedWorkTimeCalculator;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExpectedWorkTimeCalculator.
 *
 * @internal
 */
#[CoversClass(ExpectedWorkTimeCalculator::class)]
final class ExpectedWorkTimeCalculatorTest extends TestCase
{
    private ExpectedWorkTimeCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ExpectedWorkTimeCalculator(new ContractHoursResolver());
    }

    public function testSumsFiveByEightAcrossAWorkWeekWithoutAContract(): void
    {
        // Mon 2026-06-01 .. Sun 2026-06-07: 5×8h weekdays, 0 at the weekend.
        $minutes = $this->calculator->minutesForRange(
            [],
            [],
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-07'),
        );

        self::assertSame(5 * 8 * 60, $minutes);
    }

    public function testASingleWeekendDayIsZero(): void
    {
        // Saturday.
        self::assertSame(0, $this->calculator->minutesForRange(
            [],
            [],
            new DateTimeImmutable('2026-06-06'),
            new DateTimeImmutable('2026-06-06'),
        ));
    }

    public function testPublicHolidaysDropToZero(): void
    {
        // Mon–Fri with Wednesday flagged as a holiday → only 4 working days count.
        $minutes = $this->calculator->minutesForRange(
            [],
            ['2026-06-03' => true],
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-05'),
        );

        self::assertSame(4 * 8 * 60, $minutes);
    }

    public function testUsesTheContractValidForEachDay(): void
    {
        $contract = new Contract()
            ->setStart(new DateTime('2026-01-01'))
            ->setEnd(null)
            ->setHours0(0.0)
            ->setHours1(6.0)
            ->setHours2(6.0)
            ->setHours3(6.0)
            ->setHours4(6.0)
            ->setHours5(6.0)
            ->setHours6(0.0);

        // Mon–Fri at 6h each = 30h.
        $minutes = $this->calculator->minutesForRange(
            [$contract],
            [],
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-05'),
        );

        self::assertSame(30 * 60, $minutes);
    }

    public function testReturnsZeroWhenEndPrecedesStart(): void
    {
        self::assertSame(0, $this->calculator->minutesForRange(
            [],
            [],
            new DateTimeImmutable('2026-06-05'),
            new DateTimeImmutable('2026-06-01'),
        ));
    }

    public function testEndDayIsInclusive(): void
    {
        // A single weekday.
        self::assertSame(8 * 60, $this->calculator->minutesForRange(
            [],
            [],
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-06-01'),
        ));
    }
}

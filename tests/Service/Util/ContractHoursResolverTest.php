<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Util;

use App\Entity\Contract;
use App\Service\Util\ContractHoursResolver;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContractHoursResolver.
 *
 * @internal
 */
#[CoversClass(ContractHoursResolver::class)]
final class ContractHoursResolverTest extends TestCase
{
    private ContractHoursResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ContractHoursResolver();
    }

    public function testWeekdayHoursReadsTheContractPerWeekday(): void
    {
        $contract = new Contract()
            ->setHours0(0.0)
            ->setHours1(8.0)
            ->setHours2(7.5)
            ->setHours3(8.0)
            ->setHours4(8.0)
            ->setHours5(6.0)
            ->setHours6(0.0);

        self::assertSame(0.0, $this->resolver->weekdayHours($contract, 0)); // Sunday
        self::assertSame(8.0, $this->resolver->weekdayHours($contract, 1)); // Monday
        self::assertSame(7.5, $this->resolver->weekdayHours($contract, 2));
        self::assertSame(6.0, $this->resolver->weekdayHours($contract, 5)); // Friday
        self::assertSame(0.0, $this->resolver->weekdayHours($contract, 6)); // Saturday
    }

    public function testWeekdayHoursFallsBackToFiveByEightWithoutAContract(): void
    {
        // 5×8h default: 8h Mon–Fri, 0 on the weekend.
        self::assertSame(0.0, $this->resolver->weekdayHours(null, 0)); // Sunday
        self::assertSame(8.0, $this->resolver->weekdayHours(null, 1)); // Monday
        self::assertSame(8.0, $this->resolver->weekdayHours(null, 5)); // Friday
        self::assertSame(0.0, $this->resolver->weekdayHours(null, 6)); // Saturday
    }

    public function testValidContractPicksTheCoveringContractAndIsNullBeforeAny(): void
    {
        $old = new Contract()
            ->setStart(new DateTime('2020-01-01'))
            ->setEnd(new DateTime('2020-01-31'));
        $current = new Contract()
            ->setStart(new DateTime('2020-02-01'))
            ->setEnd(null);
        // Caller passes start-DESC order (newest first), as
        // findBy(..., ['start' => 'DESC']) returns.
        $contracts = [$current, $old];

        self::assertSame($old, $this->resolver->validContract($contracts, new DateTime('2020-01-15')));
        self::assertSame($current, $this->resolver->validContract($contracts, new DateTime('2021-06-01')));
        self::assertNull($this->resolver->validContract($contracts, new DateTime('2019-12-01')));
    }
}

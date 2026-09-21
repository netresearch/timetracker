<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service;

use App\Service\ClockFactory;
use App\Service\FrozenClock;
use App\Service\SystemClock;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function is_string;

/**
 * The clock the e2e stack runs on: a fixed time, and the factory that picks it
 * when APP_FROZEN_TIME is set.
 *
 * @internal
 */
#[CoversClass(FrozenClock::class)]
#[CoversClass(ClockFactory::class)]
final class FrozenClockTest extends TestCase
{
    private ?string $envBefore = null;

    private bool $envWasSet = false;

    private ?string $serverBefore = null;

    private bool $serverWasSet = false;

    protected function setUp(): void
    {
        $this->envWasSet = isset($_ENV['APP_FROZEN_TIME']);
        $current = $_ENV['APP_FROZEN_TIME'] ?? null;
        $this->envBefore = is_string($current) ? $current : null;

        // The factory reads $_SERVER as well, so both have to be put back: a
        // case that leaves $_SERVER cleared hands a later test another clock.
        $this->serverWasSet = array_key_exists('APP_FROZEN_TIME', $_SERVER);
        $currentServer = $_SERVER['APP_FROZEN_TIME'] ?? null;
        $this->serverBefore = is_string($currentServer) ? $currentServer : null;
    }

    protected function tearDown(): void
    {
        // The factory reads the process environment, so the case has to put it back.
        if ($this->envWasSet) {
            $_ENV['APP_FROZEN_TIME'] = $this->envBefore;
        } else {
            unset($_ENV['APP_FROZEN_TIME']);
        }

        if ($this->serverWasSet) {
            $_SERVER['APP_FROZEN_TIME'] = $this->serverBefore;
        } else {
            unset($_SERVER['APP_FROZEN_TIME']);
        }
    }

    public function testAFullTimestampIsReturnedUnchanged(): void
    {
        $frozenClock = new FrozenClock('2026-09-21 14:35:07');

        self::assertSame('2026-09-21 14:35:07', $frozenClock->now()->format('Y-m-d H:i:s'));
    }

    public function testADateOnlyValueIsFrozenAtMidday(): void
    {
        // Midday, not midnight: a date-only freeze must not sit on a day boundary,
        // where a timezone shift would move it to the neighbouring day.
        $frozenClock = new FrozenClock('2026-09-21');

        self::assertSame('2026-09-21 12:00:00', $frozenClock->now()->format('Y-m-d H:i:s'));
    }

    public function testTodayCutsTheTimeOff(): void
    {
        $frozenClock = new FrozenClock('2026-09-21 14:35:07');

        self::assertSame('2026-09-21 00:00:00', $frozenClock->today()->format('Y-m-d H:i:s'));
    }

    public function testTheClockDoesNotMoveBetweenCalls(): void
    {
        $frozenClock = new FrozenClock('2026-09-21 14:35:07');

        self::assertEquals($frozenClock->now(), $frozenClock->now());
    }

    public function testAnUnparseableValueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid frozen time format: "21.09.2026"');

        // Kept and used: a bare `new` reads as a useless instantiation, and the
        // failure message says what a silent pass would mean.
        $frozenClock = new FrozenClock('21.09.2026');

        self::fail('A day-first date was accepted and froze the clock at ' . $frozenClock->now()->format('Y-m-d'));
    }

    public function testTheFactoryReturnsTheFrozenClockWhenTheVariableIsSet(): void
    {
        $_ENV['APP_FROZEN_TIME'] = '2026-09-21 14:35:07';

        $clock = ClockFactory::create();

        self::assertInstanceOf(FrozenClock::class, $clock);
        self::assertSame('2026-09-21 14:35:07', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testTheFactoryIgnoresAnEmptyVariable(): void
    {
        $_ENV['APP_FROZEN_TIME'] = '';

        self::assertInstanceOf(SystemClock::class, ClockFactory::create());
    }

    public function testTheFactoryFallsBackToTheServerArray(): void
    {
        unset($_ENV['APP_FROZEN_TIME']);
        $_SERVER['APP_FROZEN_TIME'] = '2026-01-02 03:04:05';

        $clock = ClockFactory::create();

        self::assertInstanceOf(FrozenClock::class, $clock);
        self::assertSame('2026-01-02 03:04:05', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testTheFactoryReturnsTheSystemClockWithoutTheVariable(): void
    {
        unset($_ENV['APP_FROZEN_TIME'], $_SERVER['APP_FROZEN_TIME']);

        self::assertInstanceOf(SystemClock::class, ClockFactory::create());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\EntryRepository;
use App\Service\ClockInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for EntryRepository - tests pure logic without database.
 *
 * @internal
 *
 * @covers \App\Repository\EntryRepository
 */
final class EntryRepositoryUnitTest extends TestCase
{
    /**
     * Creates a repository instance with a specific clock for testing.
     */
    private function createRepositoryWithClock(ClockInterface $clock): EntryRepository
    {
        $repository = (new ReflectionClass(EntryRepository::class))->newInstanceWithoutConstructor();

        // Use reflection to set the readonly property before it's initialized
        $reflectionClass = new ReflectionClass(EntryRepository::class);
        $clockProperty = $reflectionClass->getProperty('clock');
        $clockProperty->setValue($repository, $clock);

        return $repository;
    }

    // ==================== getCalendarDaysByWorkDays tests ====================

    public function testGetCalendarDaysByWorkDaysReturnsZeroForZeroInput(): void
    {
        $clock = $this->createClock('2025-01-15'); // Wednesday
        $repository = $this->createRepositoryWithClock($clock);

        self::assertSame(0, $repository->getCalendarDaysByWorkDays(0));
    }

    public function testGetCalendarDaysByWorkDaysReturnsZeroForNegativeInput(): void
    {
        $clock = $this->createClock('2025-01-15'); // Wednesday
        $repository = $this->createRepositoryWithClock($clock);

        self::assertSame(0, $repository->getCalendarDaysByWorkDays(-1));
        self::assertSame(0, $repository->getCalendarDaysByWorkDays(-5));
    }

    public function testGetCalendarDaysByWorkDaysOnTuesday(): void
    {
        $clock = $this->createClock('2023-10-24'); // Tuesday
        $repository = $this->createRepositoryWithClock($clock);

        // 1 working day on Tuesday = Monday = 1 calendar day
        self::assertSame(1, $repository->getCalendarDaysByWorkDays(1));
        // 5 working days = full week = 7 calendar days (crosses weekend)
        self::assertSame(7, $repository->getCalendarDaysByWorkDays(5));
    }

    public function testGetCalendarDaysByWorkDaysOnMonday(): void
    {
        $clock = $this->createClock('2023-10-23'); // Monday
        $repository = $this->createRepositoryWithClock($clock);

        // On Monday, 1 working day spans back to Friday (Sat, Sun, Fri = 3 days)
        self::assertSame(3, $repository->getCalendarDaysByWorkDays(1));
    }

    public function testGetCalendarDaysByWorkDaysAcrossWeekend(): void
    {
        $clock = $this->createClock('2025-08-11'); // Monday
        $repository = $this->createRepositoryWithClock($clock);

        // 1 working day on Monday includes previous Fri,Sat,Sun => 3 calendar days
        self::assertSame(3, $repository->getCalendarDaysByWorkDays(1));
        // 5 working days => 7 calendar days (full week)
        self::assertSame(7, $repository->getCalendarDaysByWorkDays(5));
    }

    public function testGetCalendarDaysByWorkDaysTwoWeeks(): void
    {
        $clock = $this->createClock('2025-01-15'); // Wednesday
        $repository = $this->createRepositoryWithClock($clock);

        // 10 working days = 2 weeks = 14 calendar days
        self::assertSame(14, $repository->getCalendarDaysByWorkDays(10));
    }

    private function createClock(string $date): ClockInterface
    {
        return new class($date) implements ClockInterface {
            public function __construct(private readonly string $date)
            {
            }

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->date . ' 12:00:00');
            }

            public function today(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->date);
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\EntryRepository;
use App\Service\ClockInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for EntryRepository pure logic methods.
 *
 * @internal
 */
#[CoversClass(EntryRepository::class)]
final class EntryRepositoryTest extends TestCase
{
    public function testGetCalendarDaysByWorkDaysAcrossWeekend(): void
    {
        // Clock that says today is Monday (1)
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2025-08-11 12:00:00');
            }

            public function today(): DateTimeImmutable
            {
                return new DateTimeImmutable('2025-08-11 00:00:00');
            }
        };

        // Avoid touching Doctrine by creating a partial instance without constructor
        $entryRepository = (new ReflectionClass(EntryRepository::class))->newInstanceWithoutConstructor();
        $reflectionProperty = (new ReflectionClass(EntryRepository::class))->getProperty('clock');
        $reflectionProperty->setValue($entryRepository, $clock);

        // 1 working day on Monday should include previous Fri,Sat,Sun => 3 calendar days
        self::assertSame(3, $entryRepository->getCalendarDaysByWorkDays(1));

        // 5 working days => 7 calendar days (full week)
        self::assertSame(7, $entryRepository->getCalendarDaysByWorkDays(5));
    }

    public function testGetCalendarDaysByWorkDaysBasics(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2023-10-24 12:00:00');
            }

            public function today(): DateTimeImmutable
            {
                return new DateTimeImmutable('2023-10-24');
            } // Tuesday
        };
        // Avoid touching Doctrine by creating a partial mock that bypasses parent constructor
        $entryRepository = (new ReflectionClass(EntryRepository::class))->newInstanceWithoutConstructor();
        // Inject clock via reflection
        $reflectionProperty = (new ReflectionClass(EntryRepository::class))->getProperty('clock');
        $reflectionProperty->setValue($entryRepository, $clock);

        self::assertSame(0, $entryRepository->getCalendarDaysByWorkDays(0));
        self::assertSame(1, $entryRepository->getCalendarDaysByWorkDays(1)); // Tuesday -> 1
        self::assertSame(7, $entryRepository->getCalendarDaysByWorkDays(5));
    }

    public function testGetCalendarDaysByWorkDaysMondayEdge(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2023-10-23 12:00:00');
            }

            public function today(): DateTimeImmutable
            {
                return new DateTimeImmutable('2023-10-23');
            } // Monday
        };
        // Avoid touching Doctrine by creating a partial mock that bypasses parent constructor
        $entryRepository = (new ReflectionClass(EntryRepository::class))->newInstanceWithoutConstructor();
        $reflectionProperty = (new ReflectionClass(EntryRepository::class))->getProperty('clock');
        $reflectionProperty->setValue($entryRepository, $clock);

        self::assertSame(3, $entryRepository->getCalendarDaysByWorkDays(1)); // Monday spans back to Friday
    }

    public function testGetCalendarDaysByWorkDaysNegativeReturnsZero(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2023-10-24 12:00:00');
            }

            public function today(): DateTimeImmutable
            {
                return new DateTimeImmutable('2023-10-24');
            }
        };

        $entryRepository = (new ReflectionClass(EntryRepository::class))->newInstanceWithoutConstructor();
        $reflectionProperty = (new ReflectionClass(EntryRepository::class))->getProperty('clock');
        $reflectionProperty->setValue($entryRepository, $clock);

        self::assertSame(0, $entryRepository->getCalendarDaysByWorkDays(-5));
    }

    public function testGetCalendarDaysByWorkDaysFridaySpansWeekend(): void
    {
        // Clock that says today is Friday
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2023-10-27 12:00:00');
            }

            public function today(): DateTimeImmutable
            {
                return new DateTimeImmutable('2023-10-27');
            } // Friday
        };

        $entryRepository = (new ReflectionClass(EntryRepository::class))->newInstanceWithoutConstructor();
        $reflectionProperty = (new ReflectionClass(EntryRepository::class))->getProperty('clock');
        $reflectionProperty->setValue($entryRepository, $clock);

        // 1 working day on Friday should be just 1 calendar day
        self::assertSame(1, $entryRepository->getCalendarDaysByWorkDays(1));

        // 6 working days from Friday => spans to next week = 8 calendar days (Fri + Sat + Sun + Mon-Fri)
        self::assertSame(8, $entryRepository->getCalendarDaysByWorkDays(6));
    }

    public function testGetCalendarDaysByWorkDaysWednesday(): void
    {
        // Clock that says today is Wednesday
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2023-10-25 12:00:00');
            }

            public function today(): DateTimeImmutable
            {
                return new DateTimeImmutable('2023-10-25');
            } // Wednesday
        };

        $entryRepository = (new ReflectionClass(EntryRepository::class))->newInstanceWithoutConstructor();
        $reflectionProperty = (new ReflectionClass(EntryRepository::class))->getProperty('clock');
        $reflectionProperty->setValue($entryRepository, $clock);

        // 1 working day on Wednesday is just 1 calendar day
        self::assertSame(1, $entryRepository->getCalendarDaysByWorkDays(1));

        // 2 working days (Wed, Tue) should be 2 calendar days
        self::assertSame(2, $entryRepository->getCalendarDaysByWorkDays(2));
    }
}

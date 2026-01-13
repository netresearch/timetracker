<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\EntryRepository;
use App\Service\ClockInterface;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 *
 * @coversNothing
 */
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
        $this->getMockBuilder(ManagerRegistry::class)->disableOriginalConstructor()->getMock();
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
        $this->getMockBuilder(ManagerRegistry::class)->disableOriginalConstructor()->getMock();
        $entryRepository = (new ReflectionClass(EntryRepository::class))->newInstanceWithoutConstructor();
        $reflectionProperty = (new ReflectionClass(EntryRepository::class))->getProperty('clock');
        $reflectionProperty->setValue($entryRepository, $clock);

        self::assertSame(3, $entryRepository->getCalendarDaysByWorkDays(1)); // Monday spans back to Friday
    }
}

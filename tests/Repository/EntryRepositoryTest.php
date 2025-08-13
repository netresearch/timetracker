<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\EntryRepository;
use App\Service\ClockInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

class EntryRepositoryTest extends TestCase
{
    public function testGetCalendarDaysByWorkDaysAcrossWeekend(): void
    {
        // Clock that says today is Monday (1)
        $clock = new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2025-08-11 12:00:00');
            }
            public function today(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2025-08-11 00:00:00');
            }
        };

        // Avoid touching Doctrine by creating a partial instance without constructor
        $repo = (new \ReflectionClass(EntryRepository::class))->newInstanceWithoutConstructor();
        $prop = (new \ReflectionClass(EntryRepository::class))->getProperty('clock');
        $prop->setAccessible(true);
        $prop->setValue($repo, $clock);

        // 1 working day on Monday should include previous Fri,Sat,Sun => 3 calendar days
        $this->assertSame(3, $repo->getCalendarDaysByWorkDays(1));

        // 5 working days => 7 calendar days (full week)
        $this->assertSame(7, $repo->getCalendarDaysByWorkDays(5));
    }

    public function testGetCalendarDaysByWorkDaysBasics(): void
    {
        $clock = new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2023-10-24 12:00:00');
            }
            public function today(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2023-10-24');
            } // Tuesday
        };
        /** @var ManagerRegistry&\PHPUnit\Framework\MockObject\MockObject $reg */
        $reg = $this->getMockBuilder(ManagerRegistry::class)->disableOriginalConstructor()->getMock();
        // Avoid touching Doctrine by creating a partial mock that bypasses parent constructor
        $repo = (new \ReflectionClass(EntryRepository::class))->newInstanceWithoutConstructor();
        // Inject clock via reflection
        $prop = (new \ReflectionClass(EntryRepository::class))->getProperty('clock');
        $prop->setAccessible(true);
        $prop->setValue($repo, $clock);

        $this->assertSame(0, $repo->getCalendarDaysByWorkDays(0));
        $this->assertSame(1, $repo->getCalendarDaysByWorkDays(1)); // Tuesday -> 1
        $this->assertSame(7, $repo->getCalendarDaysByWorkDays(5));
    }

    public function testGetCalendarDaysByWorkDaysMondayEdge(): void
    {
        $clock = new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2023-10-23 12:00:00');
            }
            public function today(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2023-10-23');
            } // Monday
        };
        /** @var ManagerRegistry&\PHPUnit\Framework\MockObject\MockObject $reg */
        $reg = $this->getMockBuilder(ManagerRegistry::class)->disableOriginalConstructor()->getMock();
        $repo = (new \ReflectionClass(EntryRepository::class))->newInstanceWithoutConstructor();
        $prop = (new \ReflectionClass(EntryRepository::class))->getProperty('clock');
        $prop->setAccessible(true);
        $prop->setValue($repo, $clock);

        $this->assertSame(3, $repo->getCalendarDaysByWorkDays(1)); // Monday spans back to Friday
    }
}

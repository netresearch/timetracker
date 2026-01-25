<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Holiday;
use BadMethodCallException;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Holiday entity.
 *
 * @internal
 */
#[CoversClass(Holiday::class)]
final class HolidayTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorWithDateTimeObject(): void
    {
        $date = new DateTime('2024-12-25');
        $holiday = new Holiday($date, 'Christmas');

        self::assertSame($date, $holiday->getDay());
        self::assertSame('Christmas', $holiday->getName());
    }

    public function testConstructorWithDateString(): void
    {
        $holiday = new Holiday('2024-01-01', 'New Year');

        self::assertSame('2024-01-01', $holiday->getDay()->format('Y-m-d'));
        self::assertSame('New Year', $holiday->getName());
    }

    // ==================== Day tests ====================

    public function testSetDayThrowsBadMethodCallException(): void
    {
        $holiday = new Holiday('2024-12-25', 'Christmas');

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot modify readonly property $day after construction');

        $holiday->setDay('2024-12-26');
    }

    public function testSetDayWithDateTimeThrowsBadMethodCallException(): void
    {
        $holiday = new Holiday('2024-12-25', 'Christmas');

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot modify readonly property $day after construction');

        $holiday->setDay(new DateTime('2024-12-26'));
    }

    // ==================== Name tests ====================

    public function testSetNameThrowsBadMethodCallException(): void
    {
        $holiday = new Holiday('2024-12-25', 'Christmas');

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot modify readonly property $name after construction');

        $holiday->setName('Changed Name');
    }

    // ==================== toArray tests ====================

    public function testToArrayReturnsCorrectStructure(): void
    {
        $holiday = new Holiday('2024-12-25', 'Christmas Day');

        $array = $holiday->toArray();

        self::assertSame('25/12/2024', $array['day']);
        self::assertSame('Christmas Day', $array['description']);
    }

    public function testToArrayFormatsDateCorrectly(): void
    {
        $holiday = new Holiday('2024-01-01', 'New Year');

        $array = $holiday->toArray();

        self::assertSame('01/01/2024', $array['day']);
    }
}

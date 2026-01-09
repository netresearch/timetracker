<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\Preset;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ActivityTest extends TestCase
{
    public function testFluentInterface(): void
    {
        $activity = new Activity();

        self::assertSame(
            $activity,
            $activity
                ->setId(1)
                ->setName('Test Activity')
                ->setNeedsTicket(false)
                ->setFactor(1.0),
        );
    }

    public function testGetterSetter(): void
    {
        $activity = new Activity();

        // test id
        self::assertNull($activity->getId());
        $activity->setId(17);
        self::assertSame(17, $activity->getId());

        // test name
        $activity->setName('Test-Activity');
        self::assertSame('Test-Activity', $activity->getName());

        // test needsTicket
        $activity->setNeedsTicket(true);
        self::assertTrue($activity->getNeedsTicket());
        $activity->setNeedsTicket(false);
        self::assertFalse($activity->getNeedsTicket());

        // test factor
        $activity->setFactor(1.5);
        self::assertSame(1.5, $activity->getFactor());
    }

    public function testConstantValues(): void
    {
        // Verify constants are non-empty strings (actual values tested via isSick/isHoliday)
        $sickName = Activity::SICK;
        $holidayName = Activity::HOLIDAY;
        self::assertNotEmpty($sickName);
        self::assertNotEmpty($holidayName);
        self::assertIsString($sickName);
        self::assertIsString($holidayName);
    }

    public function testIsSick(): void
    {
        $activity = new Activity();
        $activity->setName('Regular');
        self::assertFalse($activity->isSick());

        $activity->setName(Activity::SICK);
        self::assertTrue($activity->isSick());
    }

    public function testIsHoliday(): void
    {
        $activity = new Activity();
        $activity->setName('Regular');
        self::assertFalse($activity->isHoliday());

        $activity->setName(Activity::HOLIDAY);
        self::assertTrue($activity->isHoliday());
    }

    public function testEntryRelationship(): void
    {
        $activity = new Activity();
        $entry = new Entry();

        // Test initial empty collection
        self::assertCount(0, $activity->getEntries());

        // Test adding entry
        $activity->addEntry($entry);
        self::assertCount(1, $activity->getEntries());
        self::assertTrue($activity->getEntries()->contains($entry));

        // Test removing entry
        $activity->removeEntry($entry);
        self::assertCount(0, $activity->getEntries());
        self::assertFalse($activity->getEntries()->contains($entry));
    }

    public function testPresetRelationship(): void
    {
        $activity = new Activity();
        $preset = new Preset();

        // Test initial empty collection
        self::assertCount(0, $activity->getPresets());

        // Test adding preset
        $activity->addPreset($preset);
        self::assertCount(1, $activity->getPresets());
        self::assertTrue($activity->getPresets()->contains($preset));

        // Test removing preset
        $activity->removePreset($preset);
        self::assertCount(0, $activity->getPresets());
        self::assertFalse($activity->getPresets()->contains($preset));
    }
}

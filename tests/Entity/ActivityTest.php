<?php

namespace Tests\Entity;

use PHPUnit\Framework\TestCase;
use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\Preset;

class ActivityTest extends TestCase
{
    public function testFluentInterface(): void
    {
        $activity = new Activity();

        $this->assertEquals(
            $activity,
            $activity
                ->setId(1)
                ->setName('Test Activity')
                ->setNeedsTicket(false)
                ->setFactor(1.0)
        );
    }

    public function testGetterSetter(): void
    {
        $activity = new Activity();

        // test id
        $this->assertNull($activity->getId());
        $activity->setId(17);
        $this->assertEquals(17, $activity->getId());

        // test name
        $activity->setName('Test-Activity');
        $this->assertEquals('Test-Activity', $activity->getName());

        // test needsTicket
        $activity->setNeedsTicket(true);
        $this->assertTrue($activity->getNeedsTicket());
        $activity->setNeedsTicket(false);
        $this->assertFalse($activity->getNeedsTicket());

        // test factor
        $activity->setFactor(1.5);
        $this->assertEquals(1.5, $activity->getFactor());
    }

    public function testConstantValues(): void
    {
        $this->assertEquals('Krank', Activity::SICK);
        $this->assertEquals('Urlaub', Activity::HOLIDAY);
    }

    public function testIsSick(): void
    {
        $activity = new Activity();
        $activity->setName('Regular');
        $this->assertFalse($activity->isSick());

        $activity->setName(Activity::SICK);
        $this->assertTrue($activity->isSick());
    }

    public function testIsHoliday(): void
    {
        $activity = new Activity();
        $activity->setName('Regular');
        $this->assertFalse($activity->isHoliday());

        $activity->setName(Activity::HOLIDAY);
        $this->assertTrue($activity->isHoliday());
    }

    public function testEntryRelationship(): void
    {
        $activity = new Activity();
        $entry = new Entry();

        // Test initial empty collection
        $this->assertCount(0, $activity->getEntries());

        // Test adding entry
        $activity->addEntry($entry);
        $this->assertCount(1, $activity->getEntries());
        $this->assertTrue($activity->getEntries()->contains($entry));

        // Test removing entry
        $activity->removeEntry($entry);
        $this->assertCount(0, $activity->getEntries());
        $this->assertFalse($activity->getEntries()->contains($entry));
    }

    public function testPresetRelationship(): void
    {
        $activity = new Activity();
        $preset = new Preset();

        // Test initial empty collection
        $this->assertCount(0, $activity->getPresets());

        // Test adding preset
        $activity->addPreset($preset);
        $this->assertCount(1, $activity->getPresets());
        $this->assertTrue($activity->getPresets()->contains($preset));

        // Test removing preset
        $activity->removePreset($preset);
        $this->assertCount(0, $activity->getPresets());
        $this->assertFalse($activity->getPresets()->contains($preset));
    }
}

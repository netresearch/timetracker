<?php

declare(strict_types=1);

namespace Tests\Enum;

use App\Enum\EntryClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EntryClass enum.
 *
 * @internal
 */
#[CoversClass(EntryClass::class)]
final class EntryClassTest extends TestCase
{
    // ==================== Case value tests ====================

    public function testDefaultHasValue0(): void
    {
        self::assertSame(0, EntryClass::DEFAULT->value);
    }

    public function testPlainHasValue1(): void
    {
        self::assertSame(1, EntryClass::PLAIN->value);
    }

    public function testDaybreakHasValue2(): void
    {
        self::assertSame(2, EntryClass::DAYBREAK->value);
    }

    public function testPauseHasValue4(): void
    {
        self::assertSame(4, EntryClass::PAUSE->value);
    }

    public function testOverlapHasValue8(): void
    {
        self::assertSame(8, EntryClass::OVERLAP->value);
    }

    // ==================== getDisplayName tests ====================

    public function testGetDisplayNameForDefault(): void
    {
        self::assertSame('Default', EntryClass::DEFAULT->getDisplayName());
    }

    public function testGetDisplayNameForPlain(): void
    {
        self::assertSame('Regular Work', EntryClass::PLAIN->getDisplayName());
    }

    public function testGetDisplayNameForDaybreak(): void
    {
        self::assertSame('Day Break', EntryClass::DAYBREAK->getDisplayName());
    }

    public function testGetDisplayNameForPause(): void
    {
        self::assertSame('Break/Pause', EntryClass::PAUSE->getDisplayName());
    }

    public function testGetDisplayNameForOverlap(): void
    {
        self::assertSame('Time Overlap', EntryClass::OVERLAP->getDisplayName());
    }

    // ==================== getCssClass tests ====================

    public function testGetCssClassForDefault(): void
    {
        self::assertSame('entry-default', EntryClass::DEFAULT->getCssClass());
    }

    public function testGetCssClassForPlain(): void
    {
        self::assertSame('entry-plain', EntryClass::PLAIN->getCssClass());
    }

    public function testGetCssClassForDaybreak(): void
    {
        self::assertSame('entry-daybreak', EntryClass::DAYBREAK->getCssClass());
    }

    public function testGetCssClassForPause(): void
    {
        self::assertSame('entry-pause', EntryClass::PAUSE->getCssClass());
    }

    public function testGetCssClassForOverlap(): void
    {
        self::assertSame('entry-overlap', EntryClass::OVERLAP->getCssClass());
    }

    // ==================== isRegularWork tests ====================

    public function testIsRegularWorkForDefault(): void
    {
        self::assertTrue(EntryClass::DEFAULT->isRegularWork());
    }

    public function testIsRegularWorkForPlain(): void
    {
        self::assertTrue(EntryClass::PLAIN->isRegularWork());
    }

    public function testIsRegularWorkForDaybreak(): void
    {
        self::assertFalse(EntryClass::DAYBREAK->isRegularWork());
    }

    public function testIsRegularWorkForPause(): void
    {
        self::assertFalse(EntryClass::PAUSE->isRegularWork());
    }

    public function testIsRegularWorkForOverlap(): void
    {
        self::assertFalse(EntryClass::OVERLAP->isRegularWork());
    }

    // ==================== isNonWork tests ====================

    public function testIsNonWorkForDefault(): void
    {
        self::assertFalse(EntryClass::DEFAULT->isNonWork());
    }

    public function testIsNonWorkForPlain(): void
    {
        self::assertFalse(EntryClass::PLAIN->isNonWork());
    }

    public function testIsNonWorkForDaybreak(): void
    {
        self::assertTrue(EntryClass::DAYBREAK->isNonWork());
    }

    public function testIsNonWorkForPause(): void
    {
        self::assertTrue(EntryClass::PAUSE->isNonWork());
    }

    public function testIsNonWorkForOverlap(): void
    {
        self::assertFalse(EntryClass::OVERLAP->isNonWork());
    }

    // ==================== isConflict tests ====================

    public function testIsConflictForDefault(): void
    {
        self::assertFalse(EntryClass::DEFAULT->isConflict());
    }

    public function testIsConflictForPlain(): void
    {
        self::assertFalse(EntryClass::PLAIN->isConflict());
    }

    public function testIsConflictForDaybreak(): void
    {
        self::assertFalse(EntryClass::DAYBREAK->isConflict());
    }

    public function testIsConflictForPause(): void
    {
        self::assertFalse(EntryClass::PAUSE->isConflict());
    }

    public function testIsConflictForOverlap(): void
    {
        self::assertTrue(EntryClass::OVERLAP->isConflict());
    }

    // ==================== all() tests ====================

    public function testAllReturnsAllCases(): void
    {
        $all = EntryClass::all();

        self::assertCount(5, $all);
        self::assertContains(EntryClass::DEFAULT, $all);
        self::assertContains(EntryClass::PLAIN, $all);
        self::assertContains(EntryClass::DAYBREAK, $all);
        self::assertContains(EntryClass::PAUSE, $all);
        self::assertContains(EntryClass::OVERLAP, $all);
    }

    // ==================== workTypes() tests ====================

    public function testWorkTypesReturnsDefaultAndPlain(): void
    {
        $workTypes = EntryClass::workTypes();

        self::assertCount(2, $workTypes);
        self::assertContains(EntryClass::DEFAULT, $workTypes);
        self::assertContains(EntryClass::PLAIN, $workTypes);
        self::assertNotContains(EntryClass::DAYBREAK, $workTypes);
        self::assertNotContains(EntryClass::PAUSE, $workTypes);
        self::assertNotContains(EntryClass::OVERLAP, $workTypes);
    }

    // ==================== nonWorkTypes() tests ====================

    public function testNonWorkTypesReturnsDaybreakAndPause(): void
    {
        $nonWorkTypes = EntryClass::nonWorkTypes();

        self::assertCount(2, $nonWorkTypes);
        self::assertContains(EntryClass::DAYBREAK, $nonWorkTypes);
        self::assertContains(EntryClass::PAUSE, $nonWorkTypes);
        self::assertNotContains(EntryClass::DEFAULT, $nonWorkTypes);
        self::assertNotContains(EntryClass::PLAIN, $nonWorkTypes);
        self::assertNotContains(EntryClass::OVERLAP, $nonWorkTypes);
    }

    // ==================== Type casting tests ====================

    public function testCanCreateFromInt(): void
    {
        self::assertSame(EntryClass::DEFAULT, EntryClass::from(0));
        self::assertSame(EntryClass::PLAIN, EntryClass::from(1));
        self::assertSame(EntryClass::DAYBREAK, EntryClass::from(2));
        self::assertSame(EntryClass::PAUSE, EntryClass::from(4));
        self::assertSame(EntryClass::OVERLAP, EntryClass::from(8));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(EntryClass::tryFrom(3));  // Not a valid value
        self::assertNull(EntryClass::tryFrom(5));  // Not a valid value
        self::assertNull(EntryClass::tryFrom(-1)); // Negative
    }
}

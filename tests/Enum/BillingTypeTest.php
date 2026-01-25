<?php

declare(strict_types=1);

namespace Tests\Enum;

use App\Enum\BillingType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BillingType enum.
 *
 * @internal
 */
#[CoversClass(BillingType::class)]
final class BillingTypeTest extends TestCase
{
    // ==================== Case value tests ====================

    public function testNoneHasValue0(): void
    {
        self::assertSame(0, BillingType::NONE->value);
    }

    public function testTimeAndMaterialHasValue1(): void
    {
        self::assertSame(1, BillingType::TIME_AND_MATERIAL->value);
    }

    public function testFixedPriceHasValue2(): void
    {
        self::assertSame(2, BillingType::FIXED_PRICE->value);
    }

    public function testMixedHasValue3(): void
    {
        self::assertSame(3, BillingType::MIXED->value);
    }

    // ==================== getDisplayName tests ====================

    public function testGetDisplayNameForNone(): void
    {
        self::assertSame('No Billing', BillingType::NONE->getDisplayName());
    }

    public function testGetDisplayNameForTimeAndMaterial(): void
    {
        self::assertSame('Time & Material', BillingType::TIME_AND_MATERIAL->getDisplayName());
    }

    public function testGetDisplayNameForFixedPrice(): void
    {
        self::assertSame('Fixed Price', BillingType::FIXED_PRICE->getDisplayName());
    }

    public function testGetDisplayNameForMixed(): void
    {
        self::assertSame('Mixed Billing', BillingType::MIXED->getDisplayName());
    }

    // ==================== getAbbreviation tests ====================

    public function testGetAbbreviationForNone(): void
    {
        self::assertSame('NB', BillingType::NONE->getAbbreviation());
    }

    public function testGetAbbreviationForTimeAndMaterial(): void
    {
        self::assertSame('TM', BillingType::TIME_AND_MATERIAL->getAbbreviation());
    }

    public function testGetAbbreviationForFixedPrice(): void
    {
        self::assertSame('FP', BillingType::FIXED_PRICE->getAbbreviation());
    }

    public function testGetAbbreviationForMixed(): void
    {
        self::assertSame('MX', BillingType::MIXED->getAbbreviation());
    }

    // ==================== requiresTimeTracking tests ====================

    public function testRequiresTimeTrackingForNone(): void
    {
        self::assertFalse(BillingType::NONE->requiresTimeTracking());
    }

    public function testRequiresTimeTrackingForTimeAndMaterial(): void
    {
        self::assertTrue(BillingType::TIME_AND_MATERIAL->requiresTimeTracking());
    }

    public function testRequiresTimeTrackingForFixedPrice(): void
    {
        self::assertFalse(BillingType::FIXED_PRICE->requiresTimeTracking());
    }

    public function testRequiresTimeTrackingForMixed(): void
    {
        self::assertTrue(BillingType::MIXED->requiresTimeTracking());
    }

    // ==================== allowsFixedPricing tests ====================

    public function testAllowsFixedPricingForNone(): void
    {
        self::assertFalse(BillingType::NONE->allowsFixedPricing());
    }

    public function testAllowsFixedPricingForTimeAndMaterial(): void
    {
        self::assertFalse(BillingType::TIME_AND_MATERIAL->allowsFixedPricing());
    }

    public function testAllowsFixedPricingForFixedPrice(): void
    {
        self::assertTrue(BillingType::FIXED_PRICE->allowsFixedPricing());
    }

    public function testAllowsFixedPricingForMixed(): void
    {
        self::assertTrue(BillingType::MIXED->allowsFixedPricing());
    }

    // ==================== isBillable tests ====================

    public function testIsBillableForNone(): void
    {
        self::assertFalse(BillingType::NONE->isBillable());
    }

    public function testIsBillableForTimeAndMaterial(): void
    {
        self::assertTrue(BillingType::TIME_AND_MATERIAL->isBillable());
    }

    public function testIsBillableForFixedPrice(): void
    {
        self::assertTrue(BillingType::FIXED_PRICE->isBillable());
    }

    public function testIsBillableForMixed(): void
    {
        self::assertTrue(BillingType::MIXED->isBillable());
    }

    // ==================== getDescription tests ====================

    public function testGetDescriptionForNone(): void
    {
        self::assertSame('Project is not billable to client', BillingType::NONE->getDescription());
    }

    public function testGetDescriptionForTimeAndMaterial(): void
    {
        self::assertSame('Billing based on actual time spent', BillingType::TIME_AND_MATERIAL->getDescription());
    }

    public function testGetDescriptionForFixedPrice(): void
    {
        self::assertSame('Fixed price agreement with client', BillingType::FIXED_PRICE->getDescription());
    }

    public function testGetDescriptionForMixed(): void
    {
        self::assertSame('Combination of fixed price and time-based billing', BillingType::MIXED->getDescription());
    }

    // ==================== all() tests ====================

    public function testAllReturnsAllCases(): void
    {
        $all = BillingType::all();

        self::assertCount(4, $all);
        self::assertContains(BillingType::NONE, $all);
        self::assertContains(BillingType::TIME_AND_MATERIAL, $all);
        self::assertContains(BillingType::FIXED_PRICE, $all);
        self::assertContains(BillingType::MIXED, $all);
    }

    // ==================== billableTypes() tests ====================

    public function testBillableTypesReturnsOnlyBillableTypes(): void
    {
        $billable = BillingType::billableTypes();

        self::assertCount(3, $billable);
        self::assertContains(BillingType::TIME_AND_MATERIAL, $billable);
        self::assertContains(BillingType::FIXED_PRICE, $billable);
        self::assertContains(BillingType::MIXED, $billable);
        self::assertNotContains(BillingType::NONE, $billable);
    }

    // ==================== Type casting tests ====================

    public function testCanCreateFromInt(): void
    {
        self::assertSame(BillingType::NONE, BillingType::from(0));
        self::assertSame(BillingType::TIME_AND_MATERIAL, BillingType::from(1));
        self::assertSame(BillingType::FIXED_PRICE, BillingType::from(2));
        self::assertSame(BillingType::MIXED, BillingType::from(3));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(BillingType::tryFrom(4));
        self::assertNull(BillingType::tryFrom(-1));
    }
}

<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Project billing type enumeration.
 */
enum BillingType: int
{
    case NONE = 0;
    case TIME_AND_MATERIAL = 1;
    case FIXED_PRICE = 2;
    case MIXED = 3;

    /**
     * Get display name for this billing type.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::NONE => 'No Billing',
            self::TIME_AND_MATERIAL => 'Time & Material',
            self::FIXED_PRICE => 'Fixed Price',
            self::MIXED => 'Mixed Billing',
        };
    }

    /**
     * Get short abbreviation for this billing type.
     */
    public function getAbbreviation(): string
    {
        return match ($this) {
            self::NONE => 'NB',
            self::TIME_AND_MATERIAL => 'TM',
            self::FIXED_PRICE => 'FP',
            self::MIXED => 'MX',
        };
    }

    /**
     * Check if this billing type requires time tracking.
     */
    public function requiresTimeTracking(): bool
    {
        return match ($this) {
            self::NONE => false,
            self::TIME_AND_MATERIAL, self::MIXED => true,
            self::FIXED_PRICE => false,
        };
    }

    /**
     * Check if this billing type allows fixed pricing.
     */
    public function allowsFixedPricing(): bool
    {
        return match ($this) {
            self::FIXED_PRICE, self::MIXED => true,
            self::NONE, self::TIME_AND_MATERIAL => false,
        };
    }

    /**
     * Check if this project is billable.
     */
    public function isBillable(): bool
    {
        return self::NONE !== $this;
    }

    /**
     * Get description for this billing type.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::NONE => 'Project is not billable to client',
            self::TIME_AND_MATERIAL => 'Billing based on actual time spent',
            self::FIXED_PRICE => 'Fixed price agreement with client',
            self::MIXED => 'Combination of fixed price and time-based billing',
        };
    }

    /**
     * Get all available billing types.
     *
     * @return self[]
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Get billable types only.
     *
     * @return self[]
     */
    public static function billableTypes(): array
    {
        return [self::TIME_AND_MATERIAL, self::FIXED_PRICE, self::MIXED];
    }
}

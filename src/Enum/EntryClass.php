<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Entry class enumeration for time tracking categories.
 */
enum EntryClass: int
{
    /**
     * Legacy/default entry class for entries without explicit classification.
     * Exists in database from before enum was introduced.
     */
    case DEFAULT = 0;
    case PLAIN = 1;
    case DAYBREAK = 2;
    case PAUSE = 4;
    case OVERLAP = 8;

    /**
     * Get display name for this entry class.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::DEFAULT => 'Default',
            self::PLAIN => 'Regular Work',
            self::DAYBREAK => 'Day Break',
            self::PAUSE => 'Break/Pause',
            self::OVERLAP => 'Time Overlap',
        };
    }

    /**
     * Get CSS class name for styling.
     */
    public function getCssClass(): string
    {
        return match ($this) {
            self::DEFAULT => 'entry-default',
            self::PLAIN => 'entry-plain',
            self::DAYBREAK => 'entry-daybreak',
            self::PAUSE => 'entry-pause',
            self::OVERLAP => 'entry-overlap',
        };
    }

    /**
     * Check if this is a regular work entry.
     */
    public function isRegularWork(): bool
    {
        return self::PLAIN === $this || self::DEFAULT === $this;
    }

    /**
     * Check if this entry represents a break or non-work time.
     */
    public function isNonWork(): bool
    {
        return self::PAUSE === $this || self::DAYBREAK === $this;
    }

    /**
     * Check if this entry indicates a time conflict.
     */
    public function isConflict(): bool
    {
        return self::OVERLAP === $this;
    }

    /**
     * Get all available entry classes.
     *
     * @return self[]
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Get entry classes for work time only.
     *
     * @return self[]
     */
    public static function workTypes(): array
    {
        return [self::DEFAULT, self::PLAIN];
    }

    /**
     * Get entry classes for non-work time.
     *
     * @return self[]
     */
    public static function nonWorkTypes(): array
    {
        return [self::DAYBREAK, self::PAUSE];
    }
}

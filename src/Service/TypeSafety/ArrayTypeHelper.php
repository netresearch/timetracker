<?php

declare(strict_types=1);

namespace App\Service\TypeSafety;

/**
 * Helper class for type-safe array operations.
 * Provides methods to safely extract and cast values from mixed arrays.
 */
final class ArrayTypeHelper
{
    /**
     * Safely get an integer value from an array.
     * 
     * @param array<string, mixed> $array
     * @param string $key
     * @param int|null $default
     * @return int|null
     */
    public static function getInt(array $array, string $key, ?int $default = null): ?int
    {
        if (!array_key_exists($key, $array)) {
            return $default;
        }

        $value = $array[$key];
        
        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * Safely get a string value from an array.
     * 
     * @param array<string, mixed> $array
     * @param string $key
     * @param string|null $default
     * @return string|null
     */
    public static function getString(array $array, string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $array)) {
            return $default;
        }

        $value = $array[$key];
        
        if ($value === null) {
            return $default;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Check if a key exists and has a non-null value.
     * 
     * @param array<string, mixed> $array
     * @param string $key
     * @return bool
     */
    public static function hasValue(array $array, string $key): bool
    {
        return array_key_exists($key, $array) && $array[$key] !== null;
    }
}
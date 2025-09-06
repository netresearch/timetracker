<?php

declare(strict_types=1);

namespace App\Service\TypeSafety;

use function array_key_exists;
use function is_int;
use function is_scalar;
use function is_string;

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
     *
     * @psalm-suppress PossiblyUnusedMethod - Utility method for safe array access
     */
    public static function getInt(array $array, string $key, ?int $default = null): ?int
    {
        if (!array_key_exists($key, $array)) {
            return $default;
        }

        /** @var mixed $value */
        $value = $array[$key];

        if (null === $value) {
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
     */
    public static function getString(array $array, string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $array)) {
            return $default;
        }

        /** @var mixed $value */
        $value = $array[$key];

        if (null === $value) {
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
     *
     * @psalm-suppress PossiblyUnusedMethod - Utility method for safe array checking
     */
    public static function hasValue(array $array, string $key): bool
    {
        return array_key_exists($key, $array) && null !== $array[$key];
    }
}

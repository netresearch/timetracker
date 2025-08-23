<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\HttpFoundation\Request;

final class RequestHelper
{
    public static function string(Request $request, string $key, string $default = ''): string
    {
        $value = $request->request->get($key);
        if ($value === null) {
            return $default;
        }

        return (string) $value;
    }

    public static function nullableString(Request $request, string $key): ?string
    {
        $value = $request->request->get($key);
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    public static function bool(Request $request, string $key, bool $default = false): bool
    {
        $value = $request->request->get($key);
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    public static function int(Request $request, string $key, ?int $default = null): ?int
    {
        $value = $request->request->get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    public static function float(Request $request, string $key, float $default = 0.0): float
    {
        $value = $request->request->get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return (float) str_replace(',', '.', (string) $value);
    }

    public static function dateFromFormat(Request $request, string $key, string $format): ?\DateTime
    {
        $value = self::string($request, $key);
        if ($value === '') {
            return null;
        }

        $dt = \DateTime::createFromFormat($format, $value);

        return $dt ?: null;
    }
}



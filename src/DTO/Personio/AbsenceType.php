<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Personio;

use function is_float;
use function is_int;
use function is_string;
use function mb_strtolower;

/**
 * A single Personio v2 absence type ({@see https://developer.personio.de}).
 *
 * The import maps a period's type to a TT {@see \App\Entity\Activity} by NAME
 * (ADR-024 §4: "krank" -> Krank, "urlaub" -> Urlaub); the id links a period to
 * its type, the name drives the mapping. `unit` distinguishes DAY-based leave
 * (vacation/sick — what P2 imports) from HOUR-based leave.
 */
final readonly class AbsenceType
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $category,
        public ?string $unit,
    ) {
    }

    /**
     * Build from a decoded Personio API item (stdClass).
     */
    public static function fromApiResponse(object $response): self
    {
        /** @var array<string, mixed> $data */
        $data = (array) $response;

        return new self(
            self::nestedString($data, 'id'),
            self::nestedString($data, 'name'),
            self::stringOrNull($data['category'] ?? null),
            self::stringOrNull($data['unit'] ?? null),
        );
    }

    /**
     * Personio measures this leave in whole/half DAYS (vs HOUR-based). Only
     * day-based absences map to a per-day TT entry in P2.
     */
    public function isDayBased(): bool
    {
        return null === $this->unit || 'DAY' === $this->unit;
    }

    /**
     * A lower-cased name for the ADR-024 §4 substring activity match.
     */
    public function normalizedName(): string
    {
        return mb_strtolower($this->name);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function nestedString(array $data, string $key): string
    {
        return self::stringOrNull($data[$key] ?? null) ?? '';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}

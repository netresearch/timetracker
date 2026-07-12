<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Personio;

use function is_array;
use function is_float;
use function is_int;
use function is_object;
use function is_string;

/**
 * A single Personio v2 absence period ({@see https://developer.personio.de}).
 *
 * Half days are encoded per boundary as `starts_from.type` / `ends_at.type`
 * (`FIRST_HALF` = morning, `SECOND_HALF` = afternoon; null = a full boundary) —
 * not a boolean and not a fractional amount. TT only needs "is this boundary a
 * half day", so {@see startsHalf()} / {@see endsHalf()} collapse the enum.
 *
 * `endDateTime` is nullable: an open-ended absence (e.g. long-term sick leave)
 * carries no end (ADR-024 §4).
 */
final readonly class AbsencePeriod
{
    public function __construct(
        public ?string $id,
        public string $personId,
        public string $absenceTypeId,
        public string $startDateTime,
        public ?string $startHalf,
        public ?string $endDateTime,
        public ?string $endHalf,
        public ?string $approvalStatus,
        public ?string $comment,
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
            self::stringOrNull($data['id'] ?? null),
            self::nestedString($data['person'] ?? null, 'id'),
            self::nestedString($data['absence_type'] ?? null, 'id'),
            self::nestedString($data['starts_from'] ?? null, 'date_time'),
            self::nestedStringOrNull($data['starts_from'] ?? null, 'type'),
            self::nestedStringOrNull($data['ends_at'] ?? null, 'date_time'),
            self::nestedStringOrNull($data['ends_at'] ?? null, 'type'),
            self::nestedStringOrNull($data['approval'] ?? null, 'status'),
            self::stringOrNull($data['comment'] ?? null),
        );
    }

    /**
     * Whether the start boundary is a half day (either morning or afternoon).
     */
    public function startsHalf(): bool
    {
        return null !== $this->startHalf;
    }

    /**
     * Whether the end boundary is a half day (either morning or afternoon).
     */
    public function endsHalf(): bool
    {
        return null !== $this->endHalf;
    }

    private static function nestedString(mixed $node, string $key): string
    {
        return self::nestedStringOrNull($node, $key) ?? '';
    }

    private static function nestedStringOrNull(mixed $node, string $key): ?string
    {
        if (is_object($node)) {
            $node = (array) $node;
        }

        if (is_array($node) && isset($node[$key])) {
            return self::stringOrNull($node[$key]);
        }

        return null;
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

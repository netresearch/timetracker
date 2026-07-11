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
 * A single Personio v2 attendance period ({@see https://developer.personio.de}).
 *
 * Personio has no break-minutes field: breaks are the gaps between WORK-type
 * periods, so a worked day maps to a set of these (ADR-024 §3).
 */
final readonly class AttendancePeriod
{
    public function __construct(
        public ?string $id,
        public string $personId,
        public string $type,
        public string $startDateTime,
        public ?string $endDateTime,
        public ?string $status,
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
            self::stringValue($data['type'] ?? null),
            self::nestedString($data['start'] ?? null, 'date_time'),
            self::nestedStringOrNull($data['end'] ?? null, 'date_time'),
            self::stringOrNull($data['status'] ?? null),
            self::stringOrNull($data['comment'] ?? null),
        );
    }

    /**
     * Personio marks approved attendances CONFIRMED; TT never modifies them.
     */
    public function isApproved(): bool
    {
        return 'CONFIRMED' === $this->status;
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

    private static function stringValue(mixed $value): string
    {
        return self::stringOrNull($value) ?? '';
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

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use JsonSerializable;

use function is_numeric;
use function is_string;

/**
 * One aggregation scope of an entry summary (customer / project / activity /
 * ticket): the caller's own booked minutes, everyone's total, and the estimate
 * (project scope only). Wire keys per ADR-022 §4.
 */
final readonly class ScopeSummaryDto implements JsonSerializable
{
    public function __construct(
        public string $scope,
        public string $name,
        public int $entries,
        public int $total,
        public int $own,
        public int $estimation,
    ) {
    }

    /**
     * Normalizes a raw EntryRepository::getEntrySummary row (DBAL values may
     * arrive as numeric strings) into the typed DTO.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(string $scope, array $row): self
    {
        return new self(
            scope: $scope,
            name: is_string($row['name'] ?? null) ? $row['name'] : '',
            entries: self::int($row['entries'] ?? null),
            total: self::int($row['total'] ?? null),
            own: self::int($row['own'] ?? null),
            estimation: self::int($row['estimation'] ?? null),
        );
    }

    /**
     * @return array{scope: string, name: string, entries: int, total: int, own: int, estimation: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'scope' => $this->scope,
            'name' => $this->name,
            'entries' => $this->entries,
            'total' => $this->total,
            'own' => $this->own,
            'estimation' => $this->estimation,
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

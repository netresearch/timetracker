<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Tempo;

use function is_numeric;
use function is_string;

/**
 * The `customer` object carried by a Tempo Account (ADR-026): the billing
 * entity a project's work rolls up to. `key` is the stable idempotent upsert
 * key (ADR-026 P2); `name` seeds the derived TT Customer name.
 */
final readonly class TempoCustomerRef
{
    public function __construct(
        public int $id,
        public string $key,
        public string $name,
    ) {
    }

    /**
     * Build from a decoded Tempo `customer` object, or null when the shape is
     * unusable (missing id/key/name).
     */
    public static function fromApiResponse(object $data): ?self
    {
        /** @var array<string, mixed> $arr */
        $arr = (array) $data;

        $id = $arr['id'] ?? null;
        $key = $arr['key'] ?? null;
        $name = $arr['name'] ?? null;

        if (!is_numeric($id) || !is_string($key) || !is_string($name)) {
            return null;
        }

        return new self((int) $id, $key, $name);
    }
}

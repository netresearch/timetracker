<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Tempo;

use function is_numeric;
use function is_object;
use function is_string;

/**
 * A Tempo Account usable in a project (ADR-026), from
 * GET /rest/tempo-accounts/1/account/project/{id}. The `customer` and
 * `category` objects are both optional on the live NR-JIRA data, so both are
 * nullable here.
 */
final readonly class TempoAccount
{
    public function __construct(
        public int $id,
        public string $key,
        public string $name,
        public ?TempoCustomerRef $customer,
        public ?string $categoryName,
    ) {
    }

    /**
     * Build from a decoded Tempo account object, or null when the shape is
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

        $customer = null;
        $customerRaw = $arr['customer'] ?? null;
        if (is_object($customerRaw)) {
            $customer = TempoCustomerRef::fromApiResponse($customerRaw);
        }

        $categoryName = null;
        $categoryRaw = $arr['category'] ?? null;
        if (is_object($categoryRaw)) {
            /** @var array<string, mixed> $categoryArr */
            $categoryArr = (array) $categoryRaw;
            $categoryNameRaw = $categoryArr['name'] ?? null;
            if (is_string($categoryNameRaw)) {
                $categoryName = $categoryNameRaw;
            }
        }

        return new self((int) $id, $key, $name, $customer, $categoryName);
    }
}

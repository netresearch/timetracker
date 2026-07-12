<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Tempo;

use function is_numeric;

/**
 * A Tempo account-to-project link (ADR-026), from
 * GET /rest/tempo-accounts/1/link/project/{id}. Only the account it points at
 * and the `defaultAccount` flag matter for derivation: a single default among
 * several links disambiguates a multi-customer project (ADR-026 §2).
 */
final readonly class TempoAccountLink
{
    public function __construct(
        public int $accountId,
        public bool $defaultAccount,
    ) {
    }

    /**
     * Build from a decoded Tempo link object, or null when it carries no
     * usable accountId.
     */
    public static function fromApiResponse(object $data): ?self
    {
        /** @var array<string, mixed> $arr */
        $arr = (array) $data;

        $accountId = $arr['accountId'] ?? null;
        if (!is_numeric($accountId)) {
            return null;
        }

        return new self((int) $accountId, true === ($arr['defaultAccount'] ?? false));
    }
}

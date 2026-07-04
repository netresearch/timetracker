<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;

use function in_array;
use function sprintf;

/**
 * Shared "date of last time booking" aggregate for the admin list repositories
 * (customers / projects / users): one grouped MAX(day) over the entries table.
 */
trait LastActivityTrait
{
    /**
     * Map of the given entry FK value => the date (Y-m-d) of the most recent
     * entry that references it. Entities with no entries are absent from the map.
     *
     * @return array<int, string>
     */
    public function lastActivityBy(string $column): array
    {
        // Whitelist — these are the only entry FKs we aggregate on; never user input.
        if (!in_array($column, ['customer_id', 'project_id', 'user_id'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported activity column: %s', $column));
        }

        /** @var array<int, string> $result */
        $result = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            sprintf('SELECT %1$s, MAX(day) FROM entries WHERE %1$s IS NOT NULL GROUP BY %1$s', $column),
        );

        return $result;
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;

use function in_array;
use function sprintf;

/**
 * Shared "date of last time booking" aggregate for the admin list repositories
 * (customers / projects / users): one grouped MAX(day) over the entries table.
 */
trait LastActivityTrait
{
    /**
     * Cache pool for the last-activity maps, set from each repository's
     * constructor (may be null in environments without a cache pool).
     *
     * The aggregate is a full index scan of the whole entries table — MariaDB
     * won't apply a loose index scan to the MAX(), so on prod (~240k rows) it
     * costs ~150 ms and runs on every /getAllProjects (incl. the tracking page),
     * /getAllCustomers and /getAllUsers. The value is display-only "date of last
     * booking", so a short TTL is a safe, large win: the query drops to ~0 on a
     * hit and the map is at most LAST_ACTIVITY_TTL seconds stale.
     */
    private ?CacheItemPoolInterface $lastActivityCache = null;

    private const int LAST_ACTIVITY_TTL = 300;

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

        $cacheItem = null;
        if ($this->lastActivityCache instanceof CacheItemPoolInterface) {
            $cacheItem = $this->lastActivityCache->getItem('last_activity_' . $column);
            if ($cacheItem->isHit()) {
                /** @var array<int, string> $cached */
                $cached = $cacheItem->get();

                return $cached;
            }
        }

        /** @var array<int, string> $result */
        $result = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            sprintf('SELECT %1$s, MAX(day) FROM entries WHERE %1$s IS NOT NULL GROUP BY %1$s', $column),
        );

        if (null !== $cacheItem && $this->lastActivityCache instanceof CacheItemPoolInterface) {
            $this->lastActivityCache->save($cacheItem->set($result)->expiresAfter(self::LAST_ACTIVITY_TTL));
        }

        return $result;
    }
}

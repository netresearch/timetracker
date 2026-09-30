<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when a sync run body failed and the database rejected a persisted row,
 * closing the entity manager so the run record itself can no longer be saved.
 */
final class SyncRunAbortedException extends RuntimeException
{
    public static function entityManagerClosed(Throwable $original): self
    {
        return new self('Sync run aborted: the entity manager closed mid-run (a persisted row was rejected by the database). Original error: ' . $original->getMessage(), 0, $original);
    }
}

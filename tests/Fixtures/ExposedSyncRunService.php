<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Entity\SyncRun;
use App\Service\Sync\AbstractSyncRunService;

/**
 * AbstractSyncRunService with its run lifecycle made callable from a test.
 */
final class ExposedSyncRunService extends AbstractSyncRunService
{
    /**
     * @param callable(): void $body
     */
    public function run(SyncRun $syncRun, callable $body): SyncRun
    {
        return $this->executeRun($syncRun, $body);
    }
}

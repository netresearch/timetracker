<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

interface ClockInterface
{
    /**
     * Current point in time.
     */
    public function now(): DateTimeImmutable;

    /**
     * Start of the current day (midnight) in the application's timezone.
     */
    public function today(): DateTimeImmutable;
}

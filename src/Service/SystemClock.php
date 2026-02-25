<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * Default implementation of ClockInterface using the system clock.
 */
class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today midnight');
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service;

use function is_string;

/**
 * Factory to create the appropriate clock implementation.
 *
 * Returns FrozenClock if APP_FROZEN_TIME is set, otherwise SystemClock.
 * This enables deterministic E2E testing for time-sensitive functionality.
 */
final class ClockFactory
{
    public static function create(): ClockInterface
    {
        $frozenTime = $_ENV['APP_FROZEN_TIME'] ?? $_SERVER['APP_FROZEN_TIME'] ?? null;

        if (is_string($frozenTime) && '' !== $frozenTime) {
            return new FrozenClock($frozenTime);
        }

        return new SystemClock();
    }
}

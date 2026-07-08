<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Project-estimate verdict for an entry summary (ADR-022): `none` when the
 * project has no estimate, `over` at/above it, `near` from 90 %, else `ok`.
 */
enum EstimateStatus: string
{
    case None = 'none';
    case Ok = 'ok';
    case Near = 'near';
    case Over = 'over';
}

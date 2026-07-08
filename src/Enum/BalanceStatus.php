<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Worked-vs-target verdict for a balance period (ADR-022): `behind` when IST
 * is under the SOLL accrued so far, `over` when IST exceeds the period's whole
 * SOLL, else `ok`.
 */
enum BalanceStatus: string
{
    case Ok = 'ok';
    case Behind = 'behind';
    case Over = 'over';
}

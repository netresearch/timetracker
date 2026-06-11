<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Exception;

use Exception;

/**
 * Thrown when pagination parameters are out of range.
 */
final class InvalidPaginationException extends Exception
{
}

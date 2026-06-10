<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Exception;

use Exception;

/**
 * Thrown when project subtickets cannot be synchronized from the ticket system.
 *
 * Exception codes are sensible HTTP status codes.
 */
final class SubticketSyncException extends Exception
{
}

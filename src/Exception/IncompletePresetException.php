<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Exception;

use Exception;

/**
 * Thrown when a preset is saved without the required customer, project and activity relations.
 */
final class IncompletePresetException extends Exception
{
}

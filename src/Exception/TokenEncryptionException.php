<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Thrown when token encryption or decryption fails.
 */
final class TokenEncryptionException extends RuntimeException
{
}

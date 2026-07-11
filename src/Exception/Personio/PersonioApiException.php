<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Exception\Personio;

use RuntimeException;
use Throwable;

/**
 * Raised on any non-2xx Personio API response (ADR-024).
 *
 * The HTTP status is preserved so callers can distinguish an approved-period
 * rejection (403/409) — which is parked as a conflict — from a hard error.
 */
final class PersonioApiException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

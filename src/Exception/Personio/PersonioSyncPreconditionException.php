<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Exception\Personio;

use RuntimeException;

/**
 * Raised when a Personio import or export run cannot start because the
 * configuration or the user mapping it needs is missing (ADR-024).
 *
 * The message is recorded verbatim as the run's ERROR item, so it stays a
 * stable, human-readable string.
 */
final class PersonioSyncPreconditionException extends RuntimeException
{
    public static function noActiveConfiguration(): self
    {
        return new self('no active Personio configuration');
    }

    public static function noAbsenceProject(): self
    {
        return new self('no absence project configured');
    }

    public static function noEmployeeIdMapped(): self
    {
        return new self('no Personio employee id mapped');
    }
}

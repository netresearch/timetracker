<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Exception\Ldap;

use Exception;

/**
 * Thrown when a connection to the LDAP server cannot be established.
 */
final class LdapConnectionException extends Exception
{
}

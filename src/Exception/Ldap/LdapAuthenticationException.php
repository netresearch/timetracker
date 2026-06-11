<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Exception\Ldap;

use RuntimeException;

/**
 * Thrown when LDAP authentication fails or the LDAP response cannot be processed.
 *
 * Extends RuntimeException so the one pre-refactoring throw site that used
 * RuntimeException (LdapClientService::login()) keeps its catch contract;
 * sites that previously threw plain Exception remain catchable as Exception
 * since RuntimeException extends it.
 */
final class LdapAuthenticationException extends RuntimeException
{
}

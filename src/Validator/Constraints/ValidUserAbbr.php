<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Validator\Constraints;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * The user abbreviation must be 1 to 3 characters — but only on creation or when
 * the abbreviation actually changes. Re-saving an existing user with an unchanged
 * abbreviation (e.g. just toggling "active") is grandfathered, so a legacy account
 * whose abbr is empty or over-long can still be edited/deactivated.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ValidUserAbbr extends Constraint
{
    public string $message = 'Please provide a valid user name abbreviation with 1 to 3 characters.';

    public function __construct(
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);

        $this->message = $message ?? $this->message;
    }
}

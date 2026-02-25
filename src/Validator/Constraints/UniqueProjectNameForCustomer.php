<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Validator\Constraints;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_CLASS)]
class UniqueProjectNameForCustomer extends Constraint
{
    public string $message = 'A project with the name "{{ value }}" already exists for this customer.';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}

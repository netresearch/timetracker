<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Validator\Constraints;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * The Jira prefix must be uppercase letters — but only on creation or when the
 * prefix actually changes. Re-saving an existing project with an unchanged
 * prefix is grandfathered, so a legacy project whose prefix predates the rule
 * (the fixture's 'TIM-1', and plenty of them in production) can still be edited:
 * assigning it a ticket system must not fail on a field the caller never touched.
 *
 * Same shape and same reason as ValidUserAbbr — a declarative Assert cannot see
 * the persisted value, so the check has to fetch it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ValidProjectJiraPrefix extends Constraint
{
    public string $message = 'The Jira prefix must contain only uppercase letters.';

    /**
     * @param array<string>|null $groups
     */
    public function __construct(
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);

        $this->message = $message ?? $this->message;
    }
}

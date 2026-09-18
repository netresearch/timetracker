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
 * A project name is at least three characters — but only on creation or when the
 * name actually changes. The sibling of ValidProjectJiraPrefix: the whole
 * constraint family has to be grandfathered together, or an update still fails
 * on whichever member the caller did not touch.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ValidProjectName extends Constraint
{
    public string $message = 'Please provide a valid project name with at least 3 letters.';

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

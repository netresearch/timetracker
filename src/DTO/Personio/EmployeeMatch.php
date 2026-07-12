<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Personio;

/**
 * A proposed TT user -> Personio employee-id match (ADR-024 P3): the outcome of
 * {@see \App\Service\Personio\EmployeeMatcher}. `source` records HOW it matched
 * (`email` = e-mail localpart, `name` = firstname.lastname) so a reviewer sees
 * the confidence before it is applied.
 */
final readonly class EmployeeMatch
{
    public function __construct(
        public int $userId,
        public string $username,
        public string $personId,
        public string $personName,
        public string $source,
    ) {
    }
}

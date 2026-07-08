<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of POST /api/v2/customers (ADR-022 Phase 3). A non-global
 * customer needs at least one team to be visible — enforced downstream by
 * SaveCustomerAction.
 *
 * @property list<int> $team_ids
 */
final readonly class CustomerOnboardDto
{
    /**
     * @param list<int> $team_ids
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Please provide a customer name.')]
        public string $name = '',
        public bool $global = false,
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $team_ids = [],
    ) {
    }
}

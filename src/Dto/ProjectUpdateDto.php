<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Change an existing project's onboarding surface (#688). Every field but the
 * id is optional and null means "leave as it is", so a caller can assign a
 * ticket system to a project that was onboarded without one — the case that
 * leaves its entries unsynced — without restating the rest.
 *
 * Deep validation (name uniqueness, ticket-prefix format) runs on the mapped
 * ProjectSaveDto in AdminOnboardingService, as it does for onboarding.
 */
final readonly class ProjectUpdateDto
{
    public function __construct(
        #[Assert\Positive(message: 'Please choose a project.')]
        public int $id = 0,
        public ?string $name = null,
        #[Assert\Positive(message: 'Please choose a customer.')]
        public ?int $customer_id = null,
        public ?string $jira_id = null,
        public ?bool $global = null,
        public ?bool $active = null,
        #[Assert\Positive(message: 'Please choose a ticket system.')]
        public ?int $ticket_system_id = null,
    ) {
    }
}

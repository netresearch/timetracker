<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of POST /api/v2/projects (ADR-022 Phase 3) — the minimal
 * onboarding surface; detailed configuration stays in the admin UI. Deep
 * validation (ticket-prefix format, customer existence) runs on the mapped
 * ProjectSaveDto in AdminOnboardingService.
 */
final readonly class ProjectOnboardDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Please provide a project name.')]
        public string $name = '',
        #[Assert\Positive(message: 'Please choose a customer.')]
        public int $customer_id = 0,
        public string $jira_id = '',
        public bool $global = false,
    ) {
    }
}

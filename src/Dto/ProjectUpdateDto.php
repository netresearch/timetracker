<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Change an existing project's onboarding surface (#688). Every field but the
 * id is optional and null means "leave as it is", so a caller can assign a
 * ticket system to a project that was onboarded without one — the case that
 * leaves its entries unsynced — without restating the rest.
 *
 * Carries no constraints of its own on purpose: nothing validates this DTO. The
 * ids are resolved (and refused) by AdminEntityResolver and
 * AdminOnboardingService::requireTicketSystem, and the field rules run on the
 * mapped ProjectSaveDto, as they do for onboarding. A declaration nothing reads
 * would look like enforcement.
 */
final readonly class ProjectUpdateDto
{
    public function __construct(
        public int $id = 0,
        public ?string $name = null,
        public ?int $customer_id = null,
        public ?string $jira_id = null,
        public ?bool $global = null,
        public ?bool $active = null,
        public ?int $ticket_system_id = null,
    ) {
    }
}

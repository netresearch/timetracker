<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of POST /api/v2/users (ADR-022 Phase 3). Uniqueness and
 * abbreviation rules run on the mapped UserSaveDto in AdminOnboardingService.
 * Auth source is the directory by default (no local password on onboarding).
 *
 * @property list<int> $team_ids
 */
final readonly class UserOnboardDto
{
    /**
     * @param list<int> $team_ids
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Please provide a valid user name with at least 3 letters.')]
        public string $username = '',
        #[Assert\NotBlank(message: 'Please provide a user abbreviation.')]
        public string $abbr = '',
        #[Assert\Choice(choices: ['USER', 'DEV', 'PL', 'ADMIN'], message: 'Invalid user type.')]
        public string $type = 'DEV',
        public string $locale = 'de',
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $team_ids = [],
    ) {
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of POST /project-import/confirm (ADR-026 P1): the admin-confirmed
 * rows to persist. Each row imports one Jira project as a TT Project with a
 * resolved Customer. #[Assert\Valid] cascades into the per-row constraints.
 *
 * @property list<ProjectImportConfirmRowDto> $rows
 */
final readonly class ProjectImportConfirmDto
{
    /**
     * @param list<ProjectImportConfirmRowDto> $rows
     */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(min: 1, minMessage: 'At least one row is required.')]
        public array $rows = [],
    ) {
    }
}

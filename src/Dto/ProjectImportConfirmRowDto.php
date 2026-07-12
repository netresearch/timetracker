<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One confirmed row of the ADR-026 P1 project-import review screen: import the
 * Jira project $jira_key (its key becomes the TT project's jira_id prefix) as a
 * TT Project named $project_name on ticket system $ticket_system_id.
 *
 * The customer is resolved by EITHER an explicit override ($customer_id, an
 * existing customer) OR by name ($customer_name — found by name, else created).
 * Which of the two is used, and the "non-empty name" rule, is enforced in
 * {@see \App\Service\Sync\ProjectImportConfirmationService} since it depends on
 * both fields together.
 *
 * $customer_key is the optional stable Tempo customer key (ADR-026 P2): when a
 * new-name row was derived from Tempo, the SPA forwards proposal.derived_customer_key
 * so the created (or name-matched) Customer gets the stable key, making the
 * mapping idempotent across runs. It is honoured only on the by-name path, never
 * when $customer_id picks an explicit existing customer (that keeps its identity).
 *
 * Property names are snake_case to bind directly to the SPA's JSON body (no
 * serializer name converter is configured — see the other request DTOs).
 */
final readonly class ProjectImportConfirmRowDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'A jira_key is required for each row.')]
        public string $jira_key = '',
        #[Assert\NotBlank(message: 'A project name is required for each row.')]
        public string $project_name = '',
        #[Assert\Positive(message: 'A valid ticket_system_id is required for each row.')]
        public int $ticket_system_id = 0,
        public ?int $customer_id = null,
        public ?string $customer_name = null,
        public ?string $customer_key = null,
    ) {
    }
}

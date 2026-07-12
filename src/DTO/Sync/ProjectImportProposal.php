<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DTO\Sync;

/**
 * A non-persisted proposal for importing one Jira project as a TT Project with
 * a derived Customer (ADR-026 P1a). Pure output of the derivation — the human
 * confirms or overrides each row before anything is written (ADR-026 P1).
 *
 * `derivationSource` records which precedence rule produced the customer, so
 * the review screen can flag the low-confidence ones:
 *  - {@see self::SOURCE_TEMPO}: exactly one Tempo Account customer.
 *  - {@see self::SOURCE_TEMPO_DEFAULT}: several customers, one default link.
 *  - {@see self::SOURCE_CATEGORY}: no Tempo customer, fell back to the Jira
 *    project category.
 *  - {@see self::SOURCE_AMBIGUOUS}: several customers, no single default —
 *    parked for a human ({@see $candidateCustomers}).
 *  - {@see self::SOURCE_NONE}: no Tempo customer and no category.
 *  - {@see self::SOURCE_NOT_A_PROJECT}: the key is not a Jira project.
 *  - {@see self::SOURCE_ERROR}: deriving this one key threw (Jira/Tempo). The
 *    key is surfaced with no derived customer so one bad key never kills the
 *    batch (ADR-026 P1 review note); the review screen flags it for a retry.
 */
final readonly class ProjectImportProposal
{
    public const string SOURCE_TEMPO = 'tempo';

    public const string SOURCE_TEMPO_DEFAULT = 'tempo-default';

    public const string SOURCE_CATEGORY = 'category';

    public const string SOURCE_AMBIGUOUS = 'ambiguous';

    public const string SOURCE_NONE = 'none';

    public const string SOURCE_NOT_A_PROJECT = 'not-a-project';

    public const string SOURCE_ERROR = 'error';

    /**
     * @param list<string> $candidateCustomers competing "Name [KEY]" labels, only for {@see self::SOURCE_AMBIGUOUS}
     */
    public function __construct(
        public string $jiraKey,
        public ?int $projectId,
        public ?string $projectName,
        public string $jiraIdPrefix,
        public ?string $derivedCustomerName,
        public ?string $derivedCustomerKey,
        public string $derivationSource,
        public array $candidateCustomers = [],
    ) {
    }
}

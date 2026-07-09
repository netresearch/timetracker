<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Contract;
use App\Mcp\ScopeGuard;
use App\Repository\ContractRepository;
use Mcp\Capability\Attribute\McpTool;

use function array_map;
use function array_values;

/**
 * MCP tool: list employment contracts (ADR-022 Phase 4). Admin-only.
 */
final readonly class ListContractsTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private ContractRepository $contractRepository,
    ) {
    }

    /**
     * List employment contracts with id, user id and the validity range. The
     * per-weekday hours are managed in the admin UI. Requires an administrator
     * account and the contracts:read scope.
     *
     * @return array{contracts: list<array{id: int, user_id: int, start: string, end: string|null}>}
     */
    #[McpTool(name: 'list_contracts', description: 'List employment contracts (admin only).')]
    public function listContracts(): array
    {
        $this->scopeGuard->requireAdminScope('contracts:read');

        return ['contracts' => array_values(array_map(
            static fn (Contract $contract): array => [
                'id' => (int) $contract->getId(),
                'user_id' => (int) $contract->getUser()->getId(),
                'start' => $contract->getStart()->format('Y-m-d'),
                'end' => $contract->getEnd()?->format('Y-m-d'),
            ],
            $this->contractRepository->findAll(),
        ))];
    }
}

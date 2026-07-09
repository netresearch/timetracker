<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\TicketSystem;
use App\Mcp\ScopeGuard;
use App\Repository\TicketSystemRepository;
use Mcp\Capability\Attribute\McpTool;

use function array_map;
use function array_values;

/**
 * MCP tool: list ticket systems (ADR-022 Phase 4). Admin-only. Credentials
 * (login, passwords, keys, OAuth secrets) are deliberately never returned.
 */
final readonly class ListTicketSystemsTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private TicketSystemRepository $ticketSystemRepository,
    ) {
    }

    /**
     * List ticket systems with id, name, type, URL and whether time is booked
     * to them — no credentials. Requires an administrator account and the
     * ticketsystems:read scope.
     *
     * @return array{ticketsystems: list<array{id: int, name: string, type: string, url: string, book_time: bool}>}
     */
    #[McpTool(name: 'list_ticketsystems', description: 'List ticket systems without credentials (admin only).')]
    public function listTicketSystems(): array
    {
        $this->scopeGuard->requireAdminScope('ticketsystems:read');

        return ['ticketsystems' => array_values(array_map(
            static fn (TicketSystem $ticketSystem): array => [
                'id' => (int) $ticketSystem->getId(),
                'name' => $ticketSystem->getName(),
                'type' => $ticketSystem->getTypeRaw(),
                'url' => $ticketSystem->getUrl(),
                'book_time' => $ticketSystem->getBookTime(),
            ],
            $this->ticketSystemRepository->findAll(),
        ))];
    }
}

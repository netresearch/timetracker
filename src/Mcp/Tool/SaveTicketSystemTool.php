<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Controller\Admin\SaveTicketSystemAction;
use App\Dto\TicketSystemSaveDto;
use App\Mcp\DecodesActionResponse;
use App\Mcp\ScopeGuard;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function count;

/**
 * MCP admin tool: create or update a ticket system (ADR-022 Phase 4). Both
 * gates: ROLE_ADMIN and ticketsystems:write. Credentials are write-only (never
 * returned; the response uses the entity's safe array).
 */
final readonly class SaveTicketSystemTool
{
    use DecodesActionResponse;

    public function __construct(
        private ScopeGuard $scopeGuard,
        private SaveTicketSystemAction $saveTicketSystemAction,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * Create a ticket system (omit `ticketSystemId`) or update one (pass it).
     * `login`/`password` are optional integration credentials (stored, never
     * read back). Requires an administrator account and the ticketsystems:write
     * scope.
     *
     * @throws ToolCallException on a validation failure
     *
     * @return array<array-key, mixed> the saved ticket system (no credentials)
     */
    #[McpTool(name: 'save_ticketsystem', description: 'Create or update a ticket system (admin only).')]
    public function saveTicketSystem(
        #[Schema(description: 'Ticket-system name.')]
        string $name,
        #[Schema(description: 'Type, e.g. "JIRA" or "OTRS".')]
        string $type,
        #[Schema(description: 'Base URL, e.g. "https://jira.example.com".')]
        string $url = '',
        #[Schema(description: 'Whether worklogs are booked to this system.')]
        bool $bookTime = false,
        #[Schema(description: 'Integration login (optional; write-only).')]
        string $login = '',
        #[Schema(description: 'Integration password (optional; write-only).')]
        string $password = '',
        #[Schema(description: 'Ticket URL template (optional).')]
        string $ticketUrl = '',
        #[Schema(description: 'Existing ticket-system id to update; omit to create.', minimum: 1)]
        ?int $ticketSystemId = null,
    ): array {
        $this->scopeGuard->requireAdminScope('ticketsystems:write');

        $dto = new TicketSystemSaveDto(
            id: $ticketSystemId,
            name: $name,
            type: $type,
            bookTime: $bookTime,
            url: $url,
            login: $login,
            password: $password,
            ticketUrl: $ticketUrl,
        );
        $this->assertValid($dto);

        $response = ($this->saveTicketSystemAction)($dto);
        $body = $this->decodeBody($response);
        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            throw new ToolCallException($this->errorMessageFromResponse($response, 'Failed to save the ticket system.'));
        }

        return ['ticketsystem' => $body];
    }

    private function assertValid(object $dto): void
    {
        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            throw new ToolCallException((string) $violations->get(0)->getMessage());
        }
    }
}

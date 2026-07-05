<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Controller\Tracking\DeleteEntryAction;
use App\Mcp\DecodesActionResponse;
use App\Mcp\ScopeGuard;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP tool: delete one of the caller's own time entries (ADR-021 Phase 5).
 *
 * Delegates to DeleteEntryAction so the ownership check (owner or admin/PL),
 * Jira worklog deletion and day-class recalc are the same code path as the web
 * UI — including the IDOR guard, so an entries:write token still cannot delete
 * another user's entry.
 */
final readonly class DeleteEntryTool
{
    use DecodesActionResponse;

    public function __construct(
        private ScopeGuard $scopeGuard,
        private DeleteEntryAction $deleteEntryAction,
    ) {
    }

    /**
     * Delete a time entry by id. Only the entry's owner (or an admin / project
     * lead) may delete it.
     *
     * @throws ToolCallException when the id is missing, unknown, or not deletable
     *                           by the caller
     *
     * @return array{success: bool}
     */
    #[McpTool(name: 'delete_entry', description: 'Delete one of your own time entries by id.')]
    public function deleteEntry(
        #[Schema(description: 'The id of the entry to delete.', minimum: 1)]
        int $id,
    ): array {
        $user = $this->scopeGuard->requireScope('entries:write');

        // DeleteEntryAction reads the id from the request payload; hand it a
        // minimal request carrying just that.
        $request = new Request(request: ['id' => (string) $id]);

        $response = ($this->deleteEntryAction)($request, $user);

        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            throw new ToolCallException($this->errorMessage($this->decodeBody($response), 'Failed to delete the entry.'));
        }

        return ['success' => true];
    }
}

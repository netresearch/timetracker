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

use function is_array;
use function is_int;

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
     * lead) may delete it. An entry that is one half of an agent/human pair
     * (ADR-025, written together by log_time) is deleted together with its
     * partner; `deleted` lists every id that was removed.
     *
     * @throws ToolCallException when the id is missing, unknown, or not deletable
     *                           by the caller
     *
     * @return array{success: bool, deleted: list<int>}
     */
    #[McpTool(name: 'delete_entry', description: 'Delete one of your own time entries by id. If it is half of an agent/human pair written by log_time, its partner is deleted too; the result lists all deleted ids.')]
    public function deleteEntry(
        #[Schema(description: 'The id of the entry to delete.', minimum: 1)]
        int $id,
    ): array {
        $user = $this->scopeGuard->requireScope('entries:write');

        // DeleteEntryAction reads the id from the request payload; hand it a
        // minimal request carrying just that.
        $request = new Request(request: ['id' => (string) $id]);

        $response = ($this->deleteEntryAction)($request, $user);
        $body = $this->decodeBody($response);

        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            throw new ToolCallException($this->errorMessage($body, 'Failed to delete the entry.'));
        }

        $deleted = [];
        foreach (is_array($body['deleted'] ?? null) ? $body['deleted'] : [$id] as $deletedId) {
            if (is_int($deletedId)) {
                $deleted[] = $deletedId;
            }
        }

        return ['success' => true, 'deleted' => $deleted];
    }
}

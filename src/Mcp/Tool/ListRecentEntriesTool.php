<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Entry;
use App\Enum\EntrySource;
use App\Mcp\ScopeGuard;
use App\Repository\EntryRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

use function array_filter;
use function array_map;
use function array_values;
use function max;
use function min;
use function sprintf;
use function trim;

/**
 * MCP tool: list the caller's own recent time entries (ADR-021 Phase 5).
 */
final readonly class ListRecentEntriesTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private EntryRepository $entryRepository,
    ) {
    }

    /**
     * List the authenticated user's own time entries from the last N days
     * (default 7, capped at 90), oldest first. Each entry includes its id, date,
     * start/end, duration, ticket, project/activity ids and description.
     *
     * The list is wrapped in an object — MCP structuredContent must be a JSON
     * object at the top level, never a bare array (#573, ADR-022 §4).
     *
     * @return array{entries: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'list_recent_entries',
        description: "List the authenticated user's own recent time entries.",
        annotations: new ToolAnnotations(title: 'List recent time entries', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        // The entry item shape (Entry::toArray()) is wide and evolves — keep the
        // item schema permissive so shape drift never becomes a client-side
        // validation failure.
        outputSchema: [
            'type' => 'object',
            'required' => ['entries'],
            'properties' => [
                'entries' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                ],
            ],
        ],
    )]
    public function listRecentEntries(
        #[Schema(description: 'How many days back to include (1–90).', minimum: 1, maximum: 90)]
        int $days = 7,
        #[Schema(description: 'Only "human" or only "agent" entries. Every agent booking written by log_time has a human partner, so "human" lists each booking once.')]
        ?string $source = null,
    ): array {
        $user = $this->scopeGuard->requireScope('entries:read');

        $days = max(1, min(90, $days));
        $only = null;
        if (null !== $source && '' !== trim($source)) {
            $only = EntrySource::tryFrom(trim($source))
                ?? throw new ToolCallException(sprintf('Unknown source "%s"; use "human" or "agent".', $source));
        }

        $entries = $this->entryRepository->getEntriesByUser($user, $days, false);
        if ($only instanceof EntrySource) {
            $entries = array_filter($entries, static fn (Entry $entry): bool => $only === $entry->getSource());
        }

        return ['entries' => array_values(array_map(
            static fn (Entry $entry): array => $entry->toArray(),
            $entries,
        ))];
    }
}

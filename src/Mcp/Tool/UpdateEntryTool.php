<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Controller\Tracking\SaveEntryAction;
use App\Dto\EntrySaveDto;
use App\Mcp\AdminEntityResolver;
use App\Mcp\DecodesActionResponse;
use App\Mcp\ScopeGuard;
use App\Service\EntrySummaryService;
use App\Service\EntryUpdateService;
use App\Service\TimeBalanceService;
use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\Response;

use function sprintf;

/**
 * MCP tool: edit one of the caller's own time entries (ADR-022 Phase 4).
 *
 * Partial update — every field left unset keeps the entry's current value, so
 * an agent can change one thing without blanking the rest. Delegates to the
 * same SaveEntryAction as the UI, so Jira worklog sync and day-class recalc run
 * identically. Owner-scoped: a foreign or unknown id is "no entry".
 */
final readonly class UpdateEntryTool
{
    use DecodesActionResponse;

    public function __construct(
        private ScopeGuard $scopeGuard,
        private SaveEntryAction $saveEntryAction,
        private AdminEntityResolver $resolver,
        private EntryUpdateService $entryUpdateService,
        private EntrySummaryService $entrySummaryService,
        private TimeBalanceService $timeBalanceService,
    ) {
    }

    /**
     * Change fields of an existing time entry. Pass the entry id (from
     * `list_recent_entries`) plus only the fields to change. Project/activity
     * accept a name or numeric id. Give `durationMinutes` OR `start`+`end` to
     * change the times; omit all three to keep them.
     *
     * @throws ToolCallException when the entry is not the caller's, an unknown
     *                           project/activity is given, times are malformed,
     *                           or validation fails
     *
     * @return array<array-key, mixed> the updated entry, plus ticket_info + balance
     */
    #[McpTool(name: 'update_entry', description: "Edit fields of one of the authenticated user's own time entries (partial update).")]
    public function updateEntry(
        #[Schema(description: 'The id of the entry to edit.', minimum: 1)]
        int $entryId,
        #[Schema(description: 'New project name or numeric id.')]
        ?string $project = null,
        #[Schema(description: 'New activity name or numeric id.')]
        ?string $activity = null,
        #[Schema(description: 'New ticket key, e.g. "NRS-4188". Pass "" to clear.')]
        ?string $ticket = null,
        #[Schema(description: 'New description. Pass "" to clear.')]
        ?string $description = null,
        #[Schema(description: 'New date YYYY-MM-DD.')]
        ?string $date = null,
        #[Schema(description: 'New duration in minutes (re-derives the end from the start).', minimum: 1)]
        ?int $durationMinutes = null,
        #[Schema(description: 'New start time HH:MM (24h).')]
        ?string $start = null,
        #[Schema(description: 'New end time HH:MM (24h).')]
        ?string $end = null,
    ): array {
        $user = $this->scopeGuard->requireScope('entries:write');

        $projectId = null !== $project ? $this->resolver->project($project)->getId() : null;
        $activityId = null !== $activity ? $this->resolver->activity($activity)->getId() : null;

        try {
            $dto = $this->entryUpdateService->mergedDto(
                entryId: $entryId,
                userId: (int) $user->getId(),
                projectId: $projectId,
                activityId: $activityId,
                ticket: $ticket,
                description: $description,
                date: $date,
                durationMinutes: $durationMinutes,
                start: $start,
                end: $end,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ToolCallException($invalidArgumentException->getMessage(), $invalidArgumentException->getCode(), previous: $invalidArgumentException);
        }

        if (!$dto instanceof EntrySaveDto) {
            throw new ToolCallException(sprintf('No entry with id %d.', $entryId));
        }

        $response = ($this->saveEntryAction)($dto, $user);
        $body = $this->decodeBody($response);
        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            throw new ToolCallException($this->errorMessage($body, 'Failed to update the time entry.'));
        }

        $result = [] === $body ? ['success' => true] : $body;
        $result['ticket_info'] = $this->entrySummaryService->forEntry($entryId, (int) $user->getId())?->jsonSerialize();
        $result['balance'] = $this->timeBalanceService->forUser($user)->jsonSerialize();

        return $result;
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Controller\Tracking\SaveEntryAction;
use App\Dto\EntrySaveDto;
use App\Entity\Activity;
use App\Entity\Project;
use App\Mcp\DecodesActionResponse;
use App\Mcp\ScopeGuard;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Service\ClockInterface;
use DateInterval;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\Response;

use function ctype_digit;
use function ltrim;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * MCP tool: log a time entry (ADR-021 Phase 5) — the flagship agent skill.
 *
 * Resolves project/activity by name or id (a coding agent knows names, not the
 * SPA's numeric ids) and synthesises start/end from a duration, then delegates
 * to SaveEntryAction so persistence, Jira worklog sync and day-class recalc are
 * the exact same code path as the web UI.
 */
final readonly class LogTimeTool
{
    use DecodesActionResponse;

    private const string DEFAULT_START = '09:00:00';

    public function __construct(
        private ScopeGuard $scopeGuard,
        private SaveEntryAction $saveEntryAction,
        private ProjectRepository $projectRepository,
        private ActivityRepository $activityRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Log a time entry for the authenticated user. Give the project and activity
     * by name or numeric id (use `list_projects` / `list_activities` to discover
     * them). Provide either `durationMinutes` (start defaults to 09:00) or an
     * explicit `start`+`end`. Date defaults to today.
     *
     * @throws ToolCallException on unknown project/activity, missing
     *                           duration/time, or a validation failure
     *
     * @return array<array-key, mixed> the created entry
     */
    #[McpTool(name: 'log_time', description: 'Log a time entry (worklog) for the authenticated user.')]
    public function logTime(
        #[Schema(description: 'Project name or numeric id (required). See list_projects.')]
        string $project,
        #[Schema(description: 'Activity name or numeric id (required). See list_activities.')]
        string $activity,
        #[Schema(description: 'Ticket key, e.g. "NRS-4188". Empty if none.')]
        string $ticket = '',
        #[Schema(description: 'Duration in minutes. Provide this or start+end.', minimum: 1)]
        ?int $durationMinutes = null,
        #[Schema(description: 'Start time HH:MM (24h). Defaults to 09:00 when only a duration is given.')]
        ?string $start = null,
        #[Schema(description: 'End time HH:MM (24h). Required if start is given without a duration.')]
        ?string $end = null,
        #[Schema(description: 'Entry date YYYY-MM-DD. Defaults to today.')]
        ?string $date = null,
        #[Schema(description: 'What was done.')]
        string $description = '',
    ): array {
        $user = $this->scopeGuard->requireScope('entries:write');

        $projectEntity = $this->resolveProject($project);
        $activityEntity = $this->resolveActivity($activity);
        $customer = $projectEntity->getCustomer();

        [$startTime, $endTime] = $this->resolveTimes($start, $end, $durationMinutes);

        $dto = new EntrySaveDto(
            date: $date ?? $this->clock->now()->format('Y-m-d'),
            start: $startTime,
            end: $endTime,
            ticket: strtoupper(trim($ticket)),
            description: $description,
            project_id: $projectEntity->getId(),
            customer_id: $customer?->getId(),
            activity_id: $activityEntity->getId(),
        );

        $response = ($this->saveEntryAction)($dto, $user);
        $body = $this->decodeBody($response);

        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            throw new ToolCallException($this->errorMessage($body, 'Failed to save the time entry.'));
        }

        return [] === $body ? ['success' => true] : $body;
    }

    /**
     * @throws ToolCallException when the project cannot be resolved
     */
    private function resolveProject(string $projectInput): Project
    {
        $projectInput = trim($projectInput);
        $project = ctype_digit(ltrim($projectInput, '-'))
            ? $this->projectRepository->findOneById((int) $projectInput)
            : $this->projectRepository->findOneBy(['name' => $projectInput]);

        if (!$project instanceof Project) {
            throw new ToolCallException(sprintf('Unknown project "%s" — use list_projects to see valid names/ids.', $projectInput));
        }

        return $project;
    }

    /**
     * @throws ToolCallException when the activity cannot be resolved
     */
    private function resolveActivity(string $activityInput): Activity
    {
        $activityInput = trim($activityInput);
        $activity = ctype_digit(ltrim($activityInput, '-'))
            ? $this->activityRepository->findOneById((int) $activityInput)
            : $this->activityRepository->findOneByName($activityInput);

        if (!$activity instanceof Activity) {
            throw new ToolCallException(sprintf('Unknown activity "%s" — use list_activities to see valid names/ids.', $activityInput));
        }

        return $activity;
    }

    /**
     * Normalise the caller's time inputs into concrete start/end clock strings.
     * Either an explicit start+end, or a duration (start defaults to 09:00).
     *
     * @throws ToolCallException when neither a duration nor start+end is given
     *
     * @return array{0: string, 1: string} [start "H:i:s", end "H:i:s"]
     */
    private function resolveTimes(?string $start, ?string $end, ?int $durationMinutes): array
    {
        if (null !== $start && null !== $end) {
            return [trim($start), trim($end)];
        }

        if (null !== $durationMinutes && $durationMinutes > 0) {
            $startTime = null !== $start ? trim($start) : self::DEFAULT_START;
            // Anchor to an arbitrary date to add the interval; only the time survives.
            $endTime = $this->clock->now()
                ->setTime(0, 0)
                ->modify($startTime)
                ->add(new DateInterval('PT' . $durationMinutes . 'M'))
                ->format('H:i:s');

            return [$startTime, $endTime];
        }

        throw new ToolCallException('Provide either durationMinutes, or both start and end.');
    }
}

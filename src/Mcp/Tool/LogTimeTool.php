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
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use App\Mcp\DecodesActionResponse;
use App\Mcp\ScopeGuard;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Service\ClockInterface;
use App\Service\DaySummaryService;
use App\Service\EntrySummaryService;
use App\Service\TimeBalanceService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\Response;

use function ctype_digit;
use function intdiv;
use function is_array;
use function is_int;
use function ltrim;
use function preg_match;
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

    private const string DEFAULT_START = '09:00';
    private const int MINUTES_PER_DAY = 24 * 60;

    public function __construct(
        private ScopeGuard $scopeGuard,
        private SaveEntryAction $saveEntryAction,
        private ProjectRepository $projectRepository,
        private ActivityRepository $activityRepository,
        private ClockInterface $clock,
        private EntrySummaryService $entrySummaryService,
        private TimeBalanceService $timeBalanceService,
        private DaySummaryService $daySummaryService,
    ) {
    }

    /**
     * Log a time entry for the authenticated user. Give the project and activity
     * by name or numeric id (use `list_projects` / `list_activities` to discover
     * them). Provide either `durationMinutes` (start defaults to 09:00) or an
     * explicit `start`+`end`. Date defaults to today.
     *
     * ADR-025 agent attribution: to record an agent session pass
     * `agentWalltimeMinutes` (the agent's wall-clock time) together with
     * `humanMinutes` (the delegated human-effort estimate) and optional
     * `touchpoints`. This dual-writes a source=agent wall-clock entry AND a
     * source=human, estimated entry in one call. The responsible user is the
     * authenticated token owner (never a client-supplied id).
     *
     * @param array{prompts?: int, reviews?: int, interventions?: int}|null $touchpoints
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
        #[Schema(description: 'Agent wall-clock minutes. Pass with humanMinutes to dual-write an agent + delegated human entry (ADR-025).', minimum: 1)]
        ?int $agentWalltimeMinutes = null,
        #[Schema(description: 'Delegated human-effort minutes. Required when agentWalltimeMinutes is given.', minimum: 1)]
        ?int $humanMinutes = null,
        #[Schema(description: 'Agent interaction counts, e.g. {"prompts":7,"reviews":2,"interventions":1}.')]
        ?array $touchpoints = null,
    ): array {
        $user = $this->scopeGuard->requireScope('entries:write');

        $projectEntity = $this->resolveProject($project);
        $activityEntity = $this->resolveActivity($activity);
        $customer = $projectEntity->getCustomer();

        if (null !== $agentWalltimeMinutes) {
            return $this->dualWrite(
                $user,
                $projectEntity,
                $activityEntity,
                $customer,
                $date ?? $this->clock->now()->format('Y-m-d'),
                strtoupper(trim($ticket)),
                $description,
                $agentWalltimeMinutes,
                $humanMinutes,
                $touchpoints,
                $start,
            );
        }

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

        $body = $this->persistEntry($dto, $user);

        $result = [] === $body ? ['success' => true] : $body;

        // Return the same context the user sees in the UI after a booking: the
        // ticket's per-scope totals ("Info" popup), the booked day so far (so
        // out-of-band bookings are visible without a second call), and the
        // running time balance with any warnings the agent should surface.
        $entryId = $this->createdEntryId($body);
        if (null !== $entryId) {
            $result['ticket_info'] = $this->entrySummaryService->forEntry($entryId, (int) $user->getId())?->jsonSerialize();
        }
        $result['day'] = $this->daySummaryService->forUser($user, $dto->date)->jsonSerialize();
        $result['balance'] = $this->timeBalanceService->forUser($user)->jsonSerialize();

        return $result;
    }

    /**
     * ADR-025: under its PAT the agent records BOTH its wall-clock time
     * (source=agent) and the delegated human-effort estimate (source=human,
     * estimated). SaveEntryAction derives the responsible user from the token
     * owner and honours source/estimated/touchpoints in the API-token channel,
     * so NO responsible id is passed here (that would be an IDOR). Both writes
     * happen in this one tool call so the pair is atomic to the agent.
     *
     * @param array{prompts?: int, reviews?: int, interventions?: int}|null $touchpoints
     *
     * @throws ToolCallException when humanMinutes is missing or a write fails
     *
     * @return array<array-key, mixed>
     */
    private function dualWrite(
        User $user,
        Project $projectEntity,
        Activity $activityEntity,
        ?Customer $customer,
        string $date,
        string $ticket,
        string $description,
        int $agentWalltimeMinutes,
        ?int $humanMinutes,
        ?array $touchpoints,
        ?string $start,
    ): array {
        if (null === $humanMinutes || $humanMinutes <= 0) {
            throw new ToolCallException('Provide humanMinutes (the delegated human-effort estimate) together with agentWalltimeMinutes.');
        }

        [$agentStart, $agentEnd] = $this->resolveTimes($start, null, $agentWalltimeMinutes);
        $agentBody = $this->persistEntry(new EntrySaveDto(
            date: $date,
            start: $agentStart,
            end: $agentEnd,
            ticket: $ticket,
            description: $description,
            project_id: $projectEntity->getId(),
            customer_id: $customer?->getId(),
            activity_id: $activityEntity->getId(),
            source: 'agent',
            estimated: false,
        ), $user);

        [$humanStart, $humanEnd] = $this->resolveTimes($start, null, $humanMinutes);
        $humanBody = $this->persistEntry(new EntrySaveDto(
            date: $date,
            start: $humanStart,
            end: $humanEnd,
            ticket: $ticket,
            description: $description,
            project_id: $projectEntity->getId(),
            customer_id: $customer?->getId(),
            activity_id: $activityEntity->getId(),
            source: 'human',
            estimated: true,
            touchpoints: $touchpoints,
        ), $user);

        $result = [
            'agent' => [] === $agentBody ? ['success' => true] : $agentBody,
            'human' => [] === $humanBody ? ['success' => true] : $humanBody,
        ];

        // The delegated human entry is the labour stream the agent reads back.
        $humanEntryId = $this->createdEntryId($humanBody);
        if (null !== $humanEntryId) {
            $result['ticket_info'] = $this->entrySummaryService->forEntry($humanEntryId, (int) $user->getId())?->jsonSerialize();
        }

        $result['day'] = $this->daySummaryService->forUser($user, $date)->jsonSerialize();
        $result['balance'] = $this->timeBalanceService->forUser($user)->jsonSerialize();

        return $result;
    }

    /**
     * Delegate one entry to SaveEntryAction, decoding its response (and raising
     * a tool error on a 4xx/5xx).
     *
     * @throws ToolCallException on a validation/persistence failure
     *
     * @return array<array-key, mixed>
     */
    private function persistEntry(EntrySaveDto $dto, User $user): array
    {
        $response = ($this->saveEntryAction)($dto, $user);
        $body = $this->decodeBody($response);

        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            throw new ToolCallException($this->errorMessage($body, 'Failed to save the time entry.'));
        }

        return $body;
    }

    /**
     * @param array<array-key, mixed> $body the decoded SaveEntryAction response
     */
    private function createdEntryId(array $body): ?int
    {
        $result = $body['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }

        return is_int($result['id'] ?? null) ? $result['id'] : null;
    }

    /**
     * @throws ToolCallException when the project cannot be resolved
     */
    private function resolveProject(string $projectInput): Project
    {
        $projectInput = trim($projectInput);
        $project = ctype_digit(ltrim($projectInput, '-'))
            ? $this->projectRepository->find((int) $projectInput)
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
            ? $this->activityRepository->find((int) $activityInput)
            : $this->activityRepository->findOneBy(['name' => $activityInput]);

        if (!$activity instanceof Activity) {
            throw new ToolCallException(sprintf('Unknown activity "%s" — use list_activities to see valid names/ids.', $activityInput));
        }

        return $activity;
    }

    /**
     * Normalise the caller's time inputs into concrete start/end clock strings:
     * either an explicit start+end, or a duration (start defaults to 09:00).
     * Uses plain minute arithmetic so a malformed time is a clear tool error
     * rather than a DateMalformedStringException, and a duration that would run
     * past midnight is rejected up front instead of silently wrapping the clock.
     *
     * @throws ToolCallException when neither a duration nor start+end is given,
     *                           the start is malformed, or the duration overruns
     *                           the day
     *
     * @return array{0: string, 1: string} [start "H:i", end "H:i"]
     */
    private function resolveTimes(?string $start, ?string $end, ?int $durationMinutes): array
    {
        if (null !== $start && null !== $end) {
            return [trim($start), trim($end)];
        }

        if (null !== $durationMinutes && $durationMinutes > 0) {
            $startTime = null !== $start ? trim($start) : self::DEFAULT_START;
            $endMinutes = $this->minutesOfDay($startTime) + $durationMinutes;

            if ($endMinutes >= self::MINUTES_PER_DAY) {
                throw new ToolCallException('The duration runs past midnight from the given start; pass explicit start and end, or split it across days.');
            }

            return [$startTime, sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60)];
        }

        throw new ToolCallException('Provide either durationMinutes, or both start and end.');
    }

    /**
     * @throws ToolCallException when $time is not a valid HH:MM[:SS] clock value
     */
    private function minutesOfDay(string $time): int
    {
        if (1 !== preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $matches)) {
            throw new ToolCallException(sprintf('Invalid start time "%s"; use HH:MM.', $time));
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours > 23 || $minutes > 59) {
            throw new ToolCallException(sprintf('Invalid start time "%s"; use HH:MM.', $time));
        }

        return ($hours * 60) + $minutes;
    }
}

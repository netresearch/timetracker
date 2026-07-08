<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service;

use App\Dto\Response\EntrySummaryDto;
use App\Dto\Response\EstimateDto;
use App\Dto\Response\ScopeSummaryDto;
use App\Entity\Entry;
use App\Enum\EstimateStatus;
use App\Repository\EntryRepository;

use function round;
use function sprintf;

/**
 * The per-scope booking aggregation shown in the tracking UI's "Info" (I) popup
 * for an entry — Customer / Project / Activity / Ticket, each with the user's own
 * booked minutes, everyone's total, and (project only) the estimate (ADR-021
 * Phase 5 / ADR-022 — the get_ticket_info MCP tool, the
 * GET /api/v2/entries/{id}/summary endpoint, and the log_time enrichment).
 *
 * Wraps EntryRepository::getEntrySummary and adds a project-estimate status the
 * consumer can act on: `over` at/above the estimate, `near` from 90 %, else `ok`
 * (or `none` when the project has no estimate).
 */
final readonly class EntrySummaryService
{
    private const int NEAR_THRESHOLD_PERCENT = 90;

    public function __construct(private EntryRepository $entryRepository)
    {
    }

    /**
     * Scoped to the caller's own entries: getEntrySummary aggregates the
     * customer/project/activity/ticket totals across all users, so returning a
     * summary for an entry the caller does not own would leak other users'
     * scope names and totals (IDOR). "Not owned" reads as "not found" (null).
     */
    public function forEntry(int $entryId, int $userId): ?EntrySummaryDto
    {
        $entry = $this->entryRepository->find($entryId);
        if (!$entry instanceof Entry || $entry->getUser()?->getId() !== $userId) {
            return null;
        }

        $seed = [
            'customer' => ['scope' => 'customer', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'project' => ['scope' => 'project', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'activity' => ['scope' => 'activity', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'ticket' => ['scope' => 'ticket', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
        ];

        $summary = $this->entryRepository->getEntrySummary($entryId, $userId, $seed);
        $project = ScopeSummaryDto::fromRow('project', $summary['project'] ?? []);
        $estimate = $this->estimate($project);

        return new EntrySummaryDto(
            customer: ScopeSummaryDto::fromRow('customer', $summary['customer'] ?? []),
            project: $project,
            activity: ScopeSummaryDto::fromRow('activity', $summary['activity'] ?? []),
            ticket: ScopeSummaryDto::fromRow('ticket', $summary['ticket'] ?? []),
            estimate: $estimate,
            warnings: $this->warnings($project->name, $estimate),
        );
    }

    /**
     * @return list<string>
     */
    private function warnings(string $projectName, EstimateDto $estimate): array
    {
        $name = '' !== $projectName ? $projectName : 'project';

        return match ($estimate->status) {
            EstimateStatus::Over => [sprintf('Project "%s" is over its estimate (%d%% — %d of %d min booked).', $name, (int) $estimate->percent, $estimate->bookedTotal, $estimate->estimation)],
            EstimateStatus::Near => [sprintf('Project "%s" is near its estimate (%d%%).', $name, (int) $estimate->percent)],
            default => [],
        };
    }

    private function estimate(ScopeSummaryDto $project): EstimateDto
    {
        if ($project->estimation <= 0) {
            return new EstimateDto(estimation: 0, bookedTotal: $project->total, percent: null, status: EstimateStatus::None);
        }

        $percent = (int) round($project->total / $project->estimation * 100);
        $status = match (true) {
            $project->total >= $project->estimation => EstimateStatus::Over,
            $percent >= self::NEAR_THRESHOLD_PERCENT => EstimateStatus::Near,
            default => EstimateStatus::Ok,
        };

        return new EstimateDto(estimation: $project->estimation, bookedTotal: $project->total, percent: $percent, status: $status);
    }
}

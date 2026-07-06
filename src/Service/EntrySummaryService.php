<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Repository\EntryRepository;

use function is_int;
use function is_string;
use function sprintf;

/**
 * The per-scope booking aggregation shown in the tracking UI's "Info" (I) popup
 * for an entry — Customer / Project / Activity / Ticket, each with the user's own
 * booked minutes, everyone's total, and (project only) the estimate (ADR-021
 * Phase 5, the get_ticket_info MCP tool + log_time enrichment).
 *
 * Wraps EntryRepository::getEntrySummary and adds a project-estimate status the
 * agent can act on: `over` at/above the estimate, `near` from 90 %, else `ok`
 * (or `none` when the project has no estimate).
 */
final readonly class EntrySummaryService
{
    private const int NEAR_THRESHOLD_PERCENT = 90;

    public function __construct(private EntryRepository $entryRepository)
    {
    }

    /**
     * @return array{
     *     customer: array<string, mixed>,
     *     project: array<string, mixed>,
     *     activity: array<string, mixed>,
     *     ticket: array<string, mixed>,
     *     estimate: array{estimation: int, booked_total: int, percent: int|null, status: string},
     *     warnings: list<string>
     * }|null null when the entry does not exist
     */
    public function forEntry(int $entryId, int $userId): ?array
    {
        if (!$this->entryRepository->find($entryId) instanceof Entry) {
            return null;
        }

        $seed = [
            'customer' => ['scope' => 'customer', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'project' => ['scope' => 'project', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'activity' => ['scope' => 'activity', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'ticket' => ['scope' => 'ticket', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
        ];

        $summary = $this->entryRepository->getEntrySummary($entryId, $userId, $seed);
        $project = $summary['project'] ?? [];
        $estimate = $this->estimate($project);

        return [
            'customer' => $summary['customer'] ?? [],
            'project' => $project,
            'activity' => $summary['activity'] ?? [],
            'ticket' => $summary['ticket'] ?? [],
            'estimate' => $estimate,
            'warnings' => $this->warnings($project, $estimate),
        ];
    }

    /**
     * @param array<string, mixed>                                                         $project
     * @param array{estimation: int, booked_total: int, percent: int|null, status: string} $estimate
     *
     * @return list<string>
     */
    private function warnings(array $project, array $estimate): array
    {
        $name = is_string($project['name'] ?? null) ? $project['name'] : 'project';

        return match ($estimate['status']) {
            'over' => [sprintf('Project "%s" is over its estimate (%d%% — %d of %d min booked).', $name, (int) $estimate['percent'], $estimate['booked_total'], $estimate['estimation'])],
            'near' => [sprintf('Project "%s" is near its estimate (%d%%).', $name, (int) $estimate['percent'])],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $project
     *
     * @return array{estimation: int, booked_total: int, percent: int|null, status: string}
     */
    private function estimate(array $project): array
    {
        $estimation = is_int($project['estimation'] ?? null) ? $project['estimation'] : 0;
        $total = is_int($project['total'] ?? null) ? $project['total'] : 0;

        if ($estimation <= 0) {
            return ['estimation' => 0, 'booked_total' => $total, 'percent' => null, 'status' => 'none'];
        }

        $percent = (int) round($total / $estimation * 100);
        $status = match (true) {
            $total >= $estimation => 'over',
            $percent >= self::NEAR_THRESHOLD_PERCENT => 'near',
            default => 'ok',
        };

        return ['estimation' => $estimation, 'booked_total' => $total, 'percent' => $percent, 'status' => $status];
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service;

use App\Dto\EntrySaveDto;
use App\Entity\Entry;
use App\Repository\EntryRepository;
use InvalidArgumentException;

use function intdiv;
use function preg_match;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Builds a merged EntrySaveDto for a PARTIAL update of an existing entry
 * (ADR-022 Phase 4): every field the caller omits keeps the entry's current
 * value, so an agent can change one thing ("set the description") without
 * blanking the rest. Shared by the update_entry MCP tool and PATCH
 * /api/v2/entries/{id}; both hand the DTO to SaveEntryAction, which enforces
 * ownership and does the actual persistence + Jira sync.
 *
 * Scoped to the caller's own entries: a foreign or unknown id yields null so
 * the caller answers "not found" (SaveEntryAction would also refuse, but this
 * keeps the not-found path explicit and leak-free).
 *
 * Lives in App\Service (no controller dependency — architecture rule): it only
 * reads the entry and produces the DTO; the caller invokes the controller.
 */
final readonly class EntryUpdateService
{
    private const int MINUTES_PER_DAY = 24 * 60;

    public function __construct(private EntryRepository $entryRepository)
    {
    }

    /**
     * @param int|null    $projectId       new project id, or null to keep
     * @param int|null    $activityId      new activity id, or null to keep
     * @param string|null $ticket          new ticket, or null to keep
     * @param string|null $description     new description, or null to keep
     * @param string|null $date            new date Y-m-d, or null to keep
     * @param int|null    $durationMinutes recompute end from start + duration
     * @param string|null $start           new start H:i, or null to keep
     * @param string|null $end             new end H:i, or null to keep
     *
     * @throws InvalidArgumentException on a malformed time or an overrun day
     */
    public function mergedDto(
        int $entryId,
        int $userId,
        ?int $projectId = null,
        ?int $activityId = null,
        ?string $ticket = null,
        ?string $description = null,
        ?string $date = null,
        ?int $durationMinutes = null,
        ?string $start = null,
        ?string $end = null,
    ): ?EntrySaveDto {
        $entry = $this->entryRepository->find($entryId);
        if (!$entry instanceof Entry || $entry->getUserId() !== $userId) {
            return null;
        }

        [$startTime, $endTime] = $this->resolveTimes($entry, $start, $end, $durationMinutes);

        $customerId = null !== $projectId
            ? null // let SaveEntryAction derive the customer from the new project
            : $entry->getCustomerId();

        return new EntrySaveDto(
            id: $entryId,
            date: $date ?? $entry->getDay()->format('Y-m-d'),
            start: $startTime,
            end: $endTime,
            ticket: null !== $ticket ? strtoupper(trim($ticket)) : $entry->getTicket(),
            description: $description ?? $entry->getDescription(),
            project_id: $projectId ?? $entry->getProjectId(),
            customer_id: $customerId,
            activity_id: $activityId ?? $entry->getActivityId(),
        );
    }

    /**
     * Resolve the new start/end, keeping the entry's current times unless the
     * caller overrides them. A duration re-derives the end from the (new or
     * current) start; an explicit start+end wins; otherwise both are kept.
     *
     * @throws InvalidArgumentException on a malformed time or a day overrun
     *
     * @return array{0: string, 1: string} [start "H:i", end "H:i"]
     */
    private function resolveTimes(Entry $entry, ?string $start, ?string $end, ?int $durationMinutes): array
    {
        $currentStart = $entry->getStart()->format('H:i');
        $currentEnd = $entry->getEnd()->format('H:i');

        // Guarded here (not only via DTO constraints): the MCP path calls this
        // service without a validated request DTO, and silently ignoring a
        // non-positive duration would masquerade as "kept the times".
        if (null !== $durationMinutes && $durationMinutes <= 0) {
            throw new InvalidArgumentException('durationMinutes must be positive.');
        }

        if (null !== $durationMinutes) {
            $startTime = null !== $start ? trim($start) : $currentStart;
            $endMinutes = $this->minutesOfDay($startTime) + $durationMinutes;
            if ($endMinutes >= self::MINUTES_PER_DAY) {
                throw new InvalidArgumentException('The duration runs past midnight from the given start; pass explicit start and end, or split it across days.');
            }

            return [$startTime, sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60)];
        }

        return [
            null !== $start ? trim($start) : $currentStart,
            null !== $end ? trim($end) : $currentEnd,
        ];
    }

    /**
     * @throws InvalidArgumentException when $time is not a valid HH:MM[:SS] clock value
     */
    private function minutesOfDay(string $time): int
    {
        if (1 !== preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid start time "%s"; use HH:MM.', $time));
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours > 23 || $minutes > 59) {
            throw new InvalidArgumentException(sprintf('Invalid start time "%s"; use HH:MM.', $time));
        }

        return ($hours * 60) + $minutes;
    }
}

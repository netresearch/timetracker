<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Entry;
use App\Enum\EntrySource;
use App\Enum\UserType;
use App\Mcp\ScopeGuard;
use App\Repository\EntryRepository;
use DateTimeImmutable;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

use function count;
use function in_array;
use function round;
use function sprintf;
use function trim;
use function usort;

/**
 * MCP tool: booked time over a date range, grouped by project, customer,
 * activity, ticket, user or day — the interpretation view of the UI for agents.
 *
 * Visibility follows the interpretation endpoints (BaseInterpretationController):
 * a DEV account only ever sees its own entries; other account types see what the
 * filters select. Without any filter the summary covers the caller's own time.
 * Human and agent minutes stay separate (ADR-025 §7); the share is of the human
 * total.
 */
final readonly class SummarizeTimeTool
{
    private const array GROUPS = ['project', 'customer', 'activity', 'ticket', 'user', 'day'];

    private const int MAX_RANGE_DAYS = 366;

    public function __construct(
        private ScopeGuard $scopeGuard,
        private EntryRepository $entryRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Sum booked minutes per group over [from, to]. Each group carries the
     * human minutes, the agent minutes, the entry count and the share of the
     * human total in percent; `totals` sums them over all groups.
     *
     * @throws ToolCallException on an invalid date, range or groupBy value
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'summarize_time', description: 'Booked time over a date range grouped by project, customer, activity, ticket, user or day, with human and agent minutes kept apart. Without filters it covers your own time.', annotations: new ToolAnnotations(title: 'Summarize booked time', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    public function summarizeTime(
        #[Schema(description: 'First day, YYYY-MM-DD. Defaults to the first day of the current month.')]
        ?string $from = null,
        #[Schema(description: 'Last day (inclusive), YYYY-MM-DD. Defaults to today. At most 366 days after from.')]
        ?string $to = null,
        #[Schema(description: 'Group by "project" (default), "customer", "activity", "ticket", "user" or "day".')]
        string $groupBy = 'project',
        #[Schema(description: 'Only this customer id.', minimum: 1)]
        ?int $customer = null,
        #[Schema(description: 'Only this project id.', minimum: 1)]
        ?int $project = null,
        #[Schema(description: 'Only this activity id.', minimum: 1)]
        ?int $activity = null,
        #[Schema(description: 'Only members of this team id.', minimum: 1)]
        ?int $team = null,
        #[Schema(description: 'Only this user id. DEV accounts always see only their own entries.', minimum: 1)]
        ?int $user = null,
        #[Schema(description: 'Only entries whose ticket matches, e.g. "NRS-4188".')]
        ?string $ticket = null,
        #[Schema(description: 'Only entries whose description contains this text.')]
        ?string $description = null,
    ): array {
        $caller = $this->scopeGuard->requireScope('reporting:read');

        if (!in_array($groupBy, self::GROUPS, true)) {
            throw new ToolCallException(sprintf('Unknown groupBy "%s"; use one of: project, customer, activity, ticket, user, day.', $groupBy));
        }

        $today = DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);
        $fromDay = $this->parseDay($from, 'from') ?? $today->modify('first day of this month');
        $toDay = $this->parseDay($to, 'to') ?? $today;
        if ($toDay < $fromDay) {
            throw new ToolCallException('"to" lies before "from".');
        }

        if ($fromDay->diff($toDay)->days > self::MAX_RANGE_DAYS) {
            throw new ToolCallException(sprintf('The range spans more than %d days; split it.', self::MAX_RANGE_DAYS));
        }

        $ticket = null !== $ticket && '' !== trim($ticket) ? trim($ticket) : null;
        $description = null !== $description && '' !== trim($description) ? trim($description) : null;
        $hasFilter = null !== $customer || null !== $project || null !== $activity || null !== $team
            || null !== $user || null !== $ticket || null !== $description;

        $entries = $this->entryRepository->findByFilterArray([
            'customer' => $customer,
            'project' => $project,
            'activity' => $activity,
            'team' => $team,
            'user' => $hasFilter ? $user : $caller->getId(),
            'ticket' => $ticket,
            'description' => $description,
            'datestart' => $fromDay->format('Y-m-d'),
            'dateend' => $toDay->format('Y-m-d'),
            'visibility_user' => UserType::DEV === $caller->getType() ? $caller->getId() : null,
            'maxResults' => null,
            'page' => null,
            'start' => null,
        ]);

        return $this->aggregate($entries, $groupBy, $fromDay, $toDay);
    }

    /**
     * @param list<Entry> $entries
     *
     * @return array<string, mixed>
     */
    private function aggregate(array $entries, string $groupBy, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var array<string, array{key: int|string, name: string, humanMinutes: int, agentMinutes: int, entries: int}> $groups */
        $groups = [];
        $humanTotal = 0;
        $agentTotal = 0;

        foreach ($entries as $entry) {
            [$key, $name] = $this->groupOf($entry, $groupBy);
            $groups[(string) $key] ??= ['key' => $key, 'name' => $name, 'humanMinutes' => 0, 'agentMinutes' => 0, 'entries' => 0];
            ++$groups[(string) $key]['entries'];

            if (EntrySource::AGENT === $entry->getSource()) {
                $groups[(string) $key]['agentMinutes'] += $entry->getDuration();
                $agentTotal += $entry->getDuration();
            } else {
                $groups[(string) $key]['humanMinutes'] += $entry->getDuration();
                $humanTotal += $entry->getDuration();
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            $group['share'] = $humanTotal > 0 ? round($group['humanMinutes'] * 100 / $humanTotal, 1) : 0.0;
            $rows[] = $group;
        }

        usort($rows, static fn (array $a, array $b): int => 'day' === $groupBy
            ? $a['key'] <=> $b['key']
            : [$b['humanMinutes'], $b['agentMinutes']] <=> [$a['humanMinutes'], $a['agentMinutes']]);

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'groupBy' => $groupBy,
            'totals' => ['humanMinutes' => $humanTotal, 'agentMinutes' => $agentTotal, 'entries' => count($entries)],
            'groups' => $rows,
        ];
    }

    /**
     * @return array{0: int|string, 1: string}
     */
    private function groupOf(Entry $entry, string $groupBy): array
    {
        return match ($groupBy) {
            'customer' => [$entry->getCustomer()?->getId() ?? 0, (string) $entry->getCustomer()?->getName()],
            'activity' => [$entry->getActivity()?->getId() ?? 0, (string) $entry->getActivity()?->getName()],
            'ticket' => [$entry->getTicket(), '' === $entry->getTicket() ? '(no ticket)' : $entry->getTicket()],
            'user' => [$entry->getUser()?->getId() ?? 0, (string) $entry->getUser()?->getUserIdentifier()],
            'day' => [$entry->getDay()->format('Y-m-d'), $entry->getDay()->format('Y-m-d')],
            default => [$entry->getProject()?->getId() ?? 0, (string) $entry->getProject()?->getName()],
        };
    }

    /**
     * @throws ToolCallException on a malformed or impossible date
     */
    private function parseDay(?string $value, string $field): ?DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (false === $day || $day->format('Y-m-d') !== trim($value)) {
            throw new ToolCallException(sprintf('Invalid %s date "%s"; use YYYY-MM-DD.', $field, $value));
        }

        return $day;
    }
}

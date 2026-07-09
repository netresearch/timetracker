<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Enum\WorklogField;
use App\ValueObject\Sync\PullResult;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTime;

use function in_array;
use function mb_substr;
use function sprintf;

/**
 * Applies remote-changed worklog fields to a TT entry (ADR-023 §2 pull/merge).
 * Validation is all-or-nothing: every failable check runs before any mutation.
 */
class EntryPullApplier
{
    public function __construct(private readonly TicketProjectResolver $ticketProjectResolver)
    {
    }

    /**
     * @param list<WorklogField> $fields remote-changed fields to apply
     */
    public function apply(Entry $entry, WorklogSnapshot $remote, array $fields, TicketSystem $ticketSystem): PullResult
    {
        $pullIssueKey = in_array(WorklogField::ISSUE_KEY, $fields, true);
        $pullStarted = in_array(WorklogField::STARTED, $fields, true);
        $pullDuration = in_array(WorklogField::DURATION, $fields, true);
        $pullComment = in_array(WorklogField::COMMENT, $fields, true);

        $project = null;
        if ($pullIssueKey) {
            $resolution = $this->ticketProjectResolver->resolve($remote->issueKey, $ticketSystem);
            $project = $resolution->project;
            if (!$project instanceof Project) {
                return new PullResult(false, 'target project unresolved: ' . $resolution->reason);
            }
        }

        $dayBefore = $entry->getDay()->format('Y-m-d');

        // Compute new times before mutating (same setTimestamp idiom as import).
        $newDuration = $pullDuration ? $remote->durationMinutes : $entry->getDuration();
        if ($pullStarted) {
            $start = new DateTime()->setTimestamp($remote->startedTimestamp);
        } else {
            $start = DateTime::createFromInterface($entry->getDay());
            $start->setTime(
                (int) $entry->getStart()->format('H'),
                (int) $entry->getStart()->format('i'),
                (int) $entry->getStart()->format('s'),
            );
        }

        $end = (clone $start)->modify(sprintf('+%d minutes', $newDuration));
        if (($pullStarted || $pullDuration) && $end->format('Y-m-d') !== $start->format('Y-m-d')) {
            return new PullResult(false, 'worklog crosses midnight');
        }

        // Mutate.
        if ($pullIssueKey && $project instanceof Project) {
            $entry->setTicket($remote->issueKey)->setProject($project);
            $customer = $project->getCustomer();
            if ($customer instanceof Customer) {
                $entry->setCustomer($customer);
            }
        }

        if ($pullStarted || $pullDuration) {
            $entry->setDay($start->format('Y-m-d'))
                ->setStart($start->format('H:i:s'))
                ->setEnd($end->format('H:i:s'))
                ->setDuration($newDuration);
        }

        if ($pullComment) {
            $entry->setDescription(mb_substr(WorklogCommentCodec::decode($remote->comment), 0, Entry::DESCRIPTION_MAX_LENGTH));
        }

        $dayAfter = $entry->getDay()->format('Y-m-d');

        return new PullResult(true, '', array_values(array_unique([$dayBefore, $dayAfter])));
    }
}

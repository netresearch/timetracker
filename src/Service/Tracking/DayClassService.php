<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Tracking;

use App\Entity\Entry;
use App\Enum\EntryClass;
use App\Enum\EntrySource;
use App\Repository\EntryRepository;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use Exception;

use function assert;
use function count;

/**
 * Sets rendering classes for pause, overlap and daybreak (extracted from BaseTrackingController).
 */
class DayClassService
{
    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
    }

    /**
     * v4 semantics: the class describes the transition BEFORE an entry. The first entry of a
     * day always marks the day break; every further entry is a pause (gap to the previous
     * entry), an overlap (starts before the previous one ended) or plain (seamless continuation).
     *
     * @throws Exception when database operations fail
     */
    public function recalculate(int $userId, string $day): void
    {
        if (0 === $userId) {
            return;
        }

        $objectManager = $this->managerRegistry->getManager();
        $objectRepository = $objectManager->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);
        // Human day shape only (ADR-025 §5): an interleaved agent entry must not
        // create a phantom pause/overlap in the human tracking timeline.
        $entries = $objectRepository->findByDay($userId, $day, EntrySource::HUMAN);

        if (0 === count($entries)) {
            return;
        }

        $dirty = false;

        $firstEntry = $entries[0];
        if (EntryClass::DAYBREAK !== $firstEntry->getClass()) {
            $firstEntry->setClass(EntryClass::DAYBREAK);
            $objectManager->persist($firstEntry);
            $dirty = true;
        }

        $counter = count($entries);
        for ($i = 1; $i < $counter; ++$i) {
            $entry = $entries[$i];
            $previous = $entries[$i - 1];

            $start = $entry->getStart();
            $previousEnd = $previous->getEnd();
            if (!$start instanceof DateTime) {
                continue;
            }
            if (!$previousEnd instanceof DateTime) {
                continue;
            }

            $entryClass = EntryClass::PLAIN;
            if ($start->format('H:i') > $previousEnd->format('H:i')) {
                $entryClass = EntryClass::PAUSE;
            } elseif ($start->format('H:i') < $previousEnd->format('H:i')) {
                $entryClass = EntryClass::OVERLAP;
            }

            if ($entryClass !== $entry->getClass()) {
                $entry->setClass($entryClass);
                $objectManager->persist($entry);
                $dirty = true;
            }
        }

        if ($dirty) {
            $objectManager->flush();
        }
    }
}

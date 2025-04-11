<?php

declare(strict_types=1);

namespace App\Service\TimeEntry;

use App\Entity\Entry;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Service for calculating and setting classes for time entries.
 * Classes determine the visual appearance and status (daybreak, pause, overlap).
 */
class ClassCalculationService
{
    /**
     * @var \Doctrine\Persistence\ManagerRegistry
     */
    private $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    /**
     * Set rendering classes for pause, overlap and daybreak for all entries on a day.
     *
     * @param integer $userId The user ID
     * @param string  $day The day in Y-m-d format
     * @return void
     */
    public function calculateClasses(int $userId, string $day): void
    {
        if ($userId === 0) {
            return;
        }

        $managerRegistry = $this->doctrine;
        $objectManager = $managerRegistry->getManager();
        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Entry::class);
        /** @var Entry[] $entries */
        $entries = $objectRepository->findByDay($userId, $day);

        if (!count($entries)) {
            return;
        }

        if (!is_object($entries[0])) {
            return;
        }

        // First entry of the day is always daybreak
        $entry = $entries[0];
        if ($entry->getClass() != Entry::CLASS_DAYBREAK) {
            $entry->setClass(Entry::CLASS_DAYBREAK);
            $objectManager->persist($entry);
            $objectManager->flush();
        }

        $counter = count($entries);

        for ($c = 1; $c < $counter; $c++) {
            $entry = $entries[$c];
            $previous = $entries[$c - 1];

            // If current entry starts after previous entry ends, it's a pause
            if ($entry->getStart()->format("H:i") > $previous->getEnd()->format("H:i")) {
                if ($entry->getClass() != Entry::CLASS_PAUSE) {
                    $entry->setClass(Entry::CLASS_PAUSE);
                    $objectManager->persist($entry);
                    $objectManager->flush();
                }
                continue;
            }

            // If current entry starts before previous entry ends, it's an overlap
            if ($entry->getStart()->format("H:i") < $previous->getEnd()->format("H:i")) {
                if ($entry->getClass() != Entry::CLASS_OVERLAP) {
                    $entry->setClass(Entry::CLASS_OVERLAP);
                    $objectManager->persist($entry);
                    $objectManager->flush();
                }
                continue;
            }

            // Otherwise it's a plain entry (starts exactly when previous ends)
            if ($entry->getClass() != Entry::CLASS_PLAIN) {
                $entry->setClass(Entry::CLASS_PLAIN);
                $objectManager->persist($entry);
                $objectManager->flush();
            }
        }
    }
}

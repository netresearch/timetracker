<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Enum\EntryClass;
use App\Exception\InvalidEntryTimeException;
use App\Repository\EntryRepository;
use DateInterval;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\Attribute\Required;

use function assert;
use function count;
use function sprintf;

abstract class BaseTrackingController extends BaseController
{
    protected LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    #[Required]
    public function setLogger(LoggerInterface $trackingLogger): void
    {
        $this->logger = $trackingLogger;
    }

    /**
     * Set rendering classes for pause, overlap and daybreak.
     *
     * @throws Exception when database operations fail
     */
    protected function calculateClasses(int $userId, string $day): void
    {
        if (0 === $userId) {
            return;
        }

        $objectManager = $this->managerRegistry->getManager();
        $objectRepository = $objectManager->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);
        $entries = $objectRepository->findByDay($userId, $day);

        if (0 === count($entries)) {
            return;
        }

        // v4 semantics: the class describes the transition BEFORE an entry.
        // The first entry of a day always marks the day break; every further
        // entry is a pause (gap to the previous entry), an overlap (starts
        // before the previous one ended) or plain (seamless continuation).
        $firstEntry = $entries[0];
        if (EntryClass::DAYBREAK !== $firstEntry->getClass()) {
            $firstEntry->setClass(EntryClass::DAYBREAK);
            $objectManager->persist($firstEntry);
            $objectManager->flush();
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
                $objectManager->flush();
            }
        }
    }

    /**
     * Write log entry using Symfony's logging.
     *
     * @param array<string, mixed>|list<mixed> $data
     */
    protected function logData(array $data, bool $raw = false): void
    {
        $context = [
            'type' => ($raw ? 'raw' : 'obj'),
            'data' => $data,
        ];

        $this->logger->info('Tracking data', $context);
    }

    /**
     * Gets a DateTime object from a date string or returns null.
     * Catches parsing exceptions internally and returns null on failure.
     */
    protected function getDateTimeFromString(?string $dateString): ?DateTime
    {
        if (null === $dateString || '' === $dateString) {
            return null;
        }

        try {
            return new DateTime($dateString);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Validates entry date and time values.
     *
     * @throws InvalidEntryTimeException when validation fails
     */
    protected function validateEntryDateTime(Entry $entry): void
    {
        $start = $entry->getStart();
        $end = $entry->getEnd();

        if (!$start instanceof DateTime || !$end instanceof DateTime) {
            throw new InvalidEntryTimeException('Entry must have valid start and end times');
        }

        if ($start >= $end) {
            throw new InvalidEntryTimeException('Entry start time must be before end time');
        }

        new DateInterval('PT23H59M');
        $dateInterval = $start->diff($end);

        if ($dateInterval->days > 0 || $dateInterval->h > 23) {
            throw new InvalidEntryTimeException('Entry duration cannot exceed 24 hours');
        }
    }

    /**
     * Calculates the duration of an entry in minutes.
     */
    protected function calculateDurationMinutes(Entry $entry): int
    {
        $start = $entry->getStart();
        $end = $entry->getEnd();

        if (!$start instanceof DateTime || !$end instanceof DateTime) {
            return 0;
        }

        return (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
    }

    /**
     * Formats duration in minutes to human readable format.
     */
    protected function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return sprintf('%dm', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if (0 === $mins) {
            return sprintf('%dh', $hours);
        }

        return sprintf('%dh %dm', $hours, $mins);
    }
}

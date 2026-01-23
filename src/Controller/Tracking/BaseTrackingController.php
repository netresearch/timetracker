<?php

declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Enum\EntryClass;
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

        $normalizedEntries = [];
        foreach ($entries as $entry) {
            // No need for instanceof check - findByDay always returns Entry[]
            $normalizedEntries[] = [
                'id' => (int) $entry->getId(),
                'start' => $entry->getStart(),
                'end' => $entry->getEnd(),
            ];
        }

        if (0 === count($normalizedEntries)) {
            return;
        }

        // Sort by start time - no isset check needed, structure is guaranteed
        usort(
            $normalizedEntries,
            static fn (array $a, array $b): int => $a['start'] <=> $b['start'],
        );
        // Calculate overlaps
        $counter = count($normalizedEntries);

        // Calculate overlaps
        for ($i = 0; $i < $counter; ++$i) {
            for ($j = $i + 1; $j < count($normalizedEntries); ++$j) {
                if ($normalizedEntries[$i]['end'] > $normalizedEntries[$j]['start']) {
                    // Mark both as overlapping
                    $this->addEntryClass($normalizedEntries[$i]['id'], EntryClass::OVERLAP);
                    $this->addEntryClass($normalizedEntries[$j]['id'], EntryClass::OVERLAP);
                }
            }
        }

        // Calculate pauses and day breaks
        for ($i = 0; $i < count($normalizedEntries) - 1; ++$i) {
            $current = $normalizedEntries[$i];
            $next = $normalizedEntries[$i + 1];

            $pauseMinutes = ($next['start']->getTimestamp() - $current['end']->getTimestamp()) / 60;

            if ($pauseMinutes > 0) {
                if ($pauseMinutes >= 60) {  // 1 hour or more
                    $this->addEntryClass($current['id'], EntryClass::DAYBREAK);
                } else {
                    $this->addEntryClass($current['id'], EntryClass::PAUSE);
                }
            }
        }
    }

    /**
     * Add a rendering class to an entry.
     */
    private function addEntryClass(int $entryId, EntryClass $entryClass): void
    {
        $entry = $this->managerRegistry->getRepository(Entry::class)->find($entryId);
        if ($entry instanceof Entry) {
            $entry->addClass($entryClass);
            $this->managerRegistry->getManager()->flush();
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
     * @throws Exception when validation fails
     */
    protected function validateEntryDateTime(Entry $entry): void
    {
        $start = $entry->getStart();
        $end = $entry->getEnd();

        if (!$start instanceof DateTime || !$end instanceof DateTime) {
            throw new Exception('Entry must have valid start and end times');
        }

        if ($start >= $end) {
            throw new Exception('Entry start time must be before end time');
        }

        new DateInterval('PT23H59M');
        $dateInterval = $start->diff($end);

        if ($dateInterval->days > 0 || $dateInterval->h > 23) {
            throw new Exception('Entry duration cannot exceed 24 hours');
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

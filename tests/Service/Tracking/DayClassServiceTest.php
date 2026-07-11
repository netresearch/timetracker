<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Tracking;

use App\Entity\Entry;
use App\Enum\EntryClass;
use App\Enum\EntrySource;
use App\Repository\EntryRepository;
use App\Service\Tracking\DayClassService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;

#[CoversClass(DayClassService::class)]
#[AllowMockObjectsWithoutExpectations]
final class DayClassServiceTest extends TestCase
{
    /**
     * @param list<Entry> $entries
     */
    private function service(array $entries): DayClassService
    {
        $entryRepository = $this->createMock(EntryRepository::class);
        $entryRepository->method('findByDay')->willReturnCallback(
            static function (int $userId, string $day, ?EntrySource $source = null) use ($entries): array {
                if ($source instanceof EntrySource) {
                    return array_values(array_filter(
                        $entries,
                        static fn (Entry $entry): bool => $entry->getSource() === $source,
                    ));
                }

                return $entries;
            },
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($entryRepository);
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManager')->willReturn($entityManager);

        return new DayClassService($managerRegistry);
    }

    private function entry(string $start, string $end): Entry
    {
        return new Entry()->setDay('2026-06-15')->setStart($start)->setEnd($end);
    }

    public function testFirstEntryBecomesDaybreakOthersClassified(): void
    {
        $first = $this->entry('09:00:00', '10:00:00');   // -> DAYBREAK
        $gap = $this->entry('10:30:00', '11:00:00');     // starts after prev end -> PAUSE
        $overlap = $this->entry('10:45:00', '11:30:00'); // starts before prev end -> OVERLAP
        $seamless = $this->entry('11:30:00', '12:00:00'); // seamless -> PLAIN

        $this->service([$first, $gap, $overlap, $seamless])->recalculate(2, '2026-06-15');

        self::assertSame(EntryClass::DAYBREAK, $first->getClass());
        self::assertSame(EntryClass::PAUSE, $gap->getClass());
        self::assertSame(EntryClass::OVERLAP, $overlap->getClass());
        self::assertSame(EntryClass::PLAIN, $seamless->getClass());
    }

    public function testAgentEntryDoesNotDistortHumanDayShape(): void
    {
        $first = $this->entry('09:00:00', '10:00:00');   // human -> DAYBREAK
        $agent = $this->entry('09:30:00', '11:00:00')->setSource(EntrySource::AGENT); // interleaved agent
        $gap = $this->entry('10:30:00', '11:00:00');     // human, after first end -> PAUSE (not OVERLAP)

        // Entries are supplied in start-ASC order, as findByDay would return them.
        $this->service([$first, $agent, $gap])->recalculate(2, '2026-06-15');

        self::assertSame(EntryClass::DAYBREAK, $first->getClass());
        // Without human-only filtering the interleaved agent entry would make this an OVERLAP.
        self::assertSame(EntryClass::PAUSE, $gap->getClass());
    }

    public function testUserIdZeroIsNoOp(): void
    {
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::never())->method('getManager');

        new DayClassService($managerRegistry)->recalculate(0, '2026-06-15');
    }
}

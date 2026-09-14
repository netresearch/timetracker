<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\EntrySource;
use App\Repository\EntryRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class EntryRepositorySyncCandidatesTest extends AbstractWebTestCase
{
    private const string IN_RANGE_DAY = '2026-06-15';

    private const string NINE = '09:00';

    private const string TEN = '10:00';

    private EntityManagerInterface $entityManager;

    private EntryRepository $entryRepository;

    private User $user;

    private TicketSystem $ticketSystem;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entryRepository);
        $this->entryRepository = $entryRepository;

        $user = $entityManager->getRepository(User::class)->findOneBy([]);
        self::assertInstanceOf(User::class, $user, 'fixture user missing');
        $this->user = $user;
        $ticketSystem = $entityManager->getRepository(TicketSystem::class)->findOneBy([]);
        self::assertInstanceOf(TicketSystem::class, $ticketSystem, 'fixture ticket system missing');
        $this->ticketSystem = $ticketSystem;
        $project = $entityManager->getRepository(Project::class)->findOneBy([]);
        self::assertInstanceOf(Project::class, $project, 'fixture project missing');
        $project->setTicketSystem($ticketSystem);
        $this->project = $project;
    }

    private function persistEntry(string $ticket, string $day, string $start, string $end, EntrySource $source = EntrySource::HUMAN): Entry
    {
        $entry = new Entry()
            ->setUser($this->user)->setProject($this->project)->setTicket($ticket)->setSource($source)
            ->setDay(new DateTime($day))->setStart(new DateTime($start))->setEnd(new DateTime($end));
        $this->entityManager->persist($entry);

        return $entry;
    }

    /**
     * @param list<Entry> $result
     *
     * @return list<int|null>
     */
    private static function idsOf(array $result): array
    {
        return array_map(static fn (Entry $entry): ?int => $entry->getId(), $result);
    }

    public function testFindJiraSyncCandidatesFiltersByUserSystemRangeAndTicket(): void
    {
        $inRange = $this->persistEntry('ABC-1', self::IN_RANGE_DAY, self::NINE, self::TEN);
        $noTicket = $this->persistEntry('', self::IN_RANGE_DAY, self::TEN, '11:00');
        $outOfRange = $this->persistEntry('ABC-2', '2026-07-15', self::NINE, self::TEN);
        // ADR-025 §7: agent walltime is never pushed as a worklog, so the sync must not pick it up.
        $agentWalltime = $this->persistEntry('ABC-3', self::IN_RANGE_DAY, self::NINE, '11:00', EntrySource::AGENT);
        $this->entityManager->flush();

        $ids = self::idsOf($this->entryRepository->findJiraSyncCandidates($this->user, $this->ticketSystem, new DateTime('2026-06-01'), new DateTime('2026-06-30')));

        self::assertContains($inRange->getId(), $ids);
        self::assertNotContains($noTicket->getId(), $ids);
        self::assertNotContains($outOfRange->getId(), $ids);
        self::assertNotContains($agentWalltime->getId(), $ids);
    }

    public function testFindByUserAndTicketSystemToSyncSkipsAgentWalltime(): void
    {
        // The legacy bulk push (JiraOAuthApiService / JiraWorkLogService) books every
        // entry this returns; agent walltime must not be among them (ADR-025 §7).
        // A far-future day puts both entries first in the newest-first result.
        $human = $this->persistEntry('ABC-10', '2030-01-02', self::NINE, self::TEN);
        $agentWalltime = $this->persistEntry('ABC-10', '2030-01-02', self::NINE, '12:00', EntrySource::AGENT);
        $this->entityManager->flush();

        $userId = $this->user->getId();
        $ticketSystemId = $this->ticketSystem->getId();
        self::assertIsInt($userId);
        self::assertIsInt($ticketSystemId);
        $ids = self::idsOf($this->entryRepository->findByUserAndTicketSystemToSync($userId, $ticketSystemId, 50));

        self::assertContains($human->getId(), $ids);
        self::assertNotContains($agentWalltime->getId(), $ids);
    }

    public function testFindByWorklogIdsAndTicketSystemKeysLinkedEntriesByWorklogId(): void
    {
        $human = $this->persistEntry('ABC-20', self::IN_RANGE_DAY, self::NINE, self::TEN)->setWorklogId(900001);
        $agentWalltime = $this->persistEntry('ABC-21', self::IN_RANGE_DAY, self::TEN, '11:00', EntrySource::AGENT)->setWorklogId(900002);
        $this->persistEntry('ABC-22', self::IN_RANGE_DAY, '11:00', '12:00')->setWorklogId(900003);
        $this->entityManager->flush();

        $found = $this->entryRepository->findByWorklogIdsAndTicketSystem([900001, 900002, 900099], $this->ticketSystem);

        self::assertEqualsCanonicalizing([900001, 900002], array_keys($found));
        self::assertSame($human, $found[900001]);
        self::assertSame($agentWalltime, $found[900002]);
        self::assertSame([], $this->entryRepository->findByWorklogIdsAndTicketSystem([], $this->ticketSystem));
    }
}

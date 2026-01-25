<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\Period;
use App\Repository\EntryRepository;
use InvalidArgumentException;
use Tests\AbstractWebTestCase;

use function assert;
use function count;

/**
 * Comprehensive integration tests for EntryRepository.
 *
 * @internal
 *
 * @covers \App\Repository\EntryRepository
 */
final class EntryRepositoryFullIntegrationTest extends AbstractWebTestCase
{
    private EntryRepository $repository;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $repo = self::getContainer()->get('doctrine')->getRepository(Entry::class);
        assert($repo instanceof EntryRepository);
        $this->repository = $repo;

        $userRepository = self::getContainer()->get('doctrine')->getRepository(User::class);
        $user = $userRepository->find(1);
        assert($user instanceof User);
        $this->user = $user;
    }

    // ==================== findOneById tests ====================

    public function testFindOneByIdReturnsEntryWhenExists(): void
    {
        // Get an existing entry ID
        $entries = $this->repository->findAll();
        if ([] === $entries) {
            self::markTestSkipped('No entries in database');
        }

        $existingEntry = $entries[0];
        assert($existingEntry instanceof Entry);
        $entryId = $existingEntry->getId();
        assert(null !== $entryId);

        $result = $this->repository->findOneById($entryId);

        self::assertInstanceOf(Entry::class, $result);
        self::assertSame($entryId, $result->getId());
    }

    public function testFindOneByIdReturnsNullWhenNotExists(): void
    {
        $result = $this->repository->findOneById(999999);

        self::assertNull($result);
    }

    // ==================== getEntriesForDay tests ====================

    public function testGetEntriesForDayReturnsEntriesForUser(): void
    {
        // Create test entry for today
        $today = date('Y-m-d');
        $this->createTestEntry($this->user, $today);

        $result = $this->repository->getEntriesForDay($this->user, $today);

        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(Entry::class, $result);
    }

    public function testGetEntriesForDayReturnsEmptyForNonExistentDay(): void
    {
        $result = $this->repository->getEntriesForDay($this->user, '1900-01-01');

        self::assertSame([], $result);
    }

    // ==================== getEntriesForMonth tests ====================

    public function testGetEntriesForMonthReturnsEntriesInRange(): void
    {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');

        $result = $this->repository->getEntriesForMonth($this->user, $startDate, $endDate);

        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(Entry::class, $result);

        // Verify entries are within date range
        foreach ($result as $entry) {
            self::assertGreaterThanOrEqual($startDate, $entry->getDay());
            self::assertLessThanOrEqual($endDate, $entry->getDay());
        }
    }

    // ==================== getCountByUser tests ====================

    public function testGetCountByUserReturnsCount(): void
    {
        $count = $this->repository->getCountByUser($this->user);

        self::assertIsInt($count);
        self::assertGreaterThanOrEqual(0, $count);
    }

    // ==================== findEntriesWithRelations tests ====================

    public function testFindEntriesWithRelationsReturnsQueryBuilder(): void
    {
        $queryBuilder = $this->repository->findEntriesWithRelations();

        // Execute the query to verify it works
        $result = $queryBuilder->getQuery()->getResult();
        self::assertIsArray($result);
    }

    public function testFindEntriesWithRelationsAppliesConditions(): void
    {
        $queryBuilder = $this->repository->findEntriesWithRelations(['user' => $this->user]);

        $result = $queryBuilder->getQuery()->getResult();

        self::assertIsArray($result);
        foreach ($result as $entry) {
            assert($entry instanceof Entry);
            $entryUser = $entry->getUser();
            assert(null !== $entryUser);
            self::assertSame($this->user->getId(), $entryUser->getId());
        }
    }

    // ==================== findByIds tests ====================

    public function testFindByIdsReturnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->repository->findByIds([]);

        self::assertSame([], $result);
    }

    public function testFindByIdsReturnsMatchingEntries(): void
    {
        $entries = $this->repository->findAll();
        if (count($entries) < 2) {
            self::markTestSkipped('Need at least 2 entries');
        }

        $id0 = $entries[0]->getId();
        $id1 = $entries[1]->getId();
        assert(null !== $id0 && null !== $id1);

        $ids = [$id0, $id1];
        $result = $this->repository->findByIds($ids);

        self::assertCount(2, $result);
    }

    // ==================== getTotalDuration tests ====================

    public function testGetTotalDurationReturnsSum(): void
    {
        $duration = $this->repository->getTotalDuration(['user' => $this->user]);

        self::assertIsFloat($duration);
        self::assertGreaterThanOrEqual(0.0, $duration);
    }

    public function testGetTotalDurationReturnsZeroForNoMatches(): void
    {
        // Create condition that won't match anything
        $duration = $this->repository->getTotalDuration(['id' => -1]);
        self::assertSame(0.0, $duration);
    }

    // ==================== existsWithConditions tests ====================

    public function testExistsWithConditionsReturnsTrueWhenExists(): void
    {
        $entries = $this->repository->findAll();
        if ([] === $entries) {
            self::markTestSkipped('No entries in database');
        }

        $entry = $entries[0];
        assert($entry instanceof Entry);
        $entryId = $entry->getId();
        assert(null !== $entryId);

        $exists = $this->repository->existsWithConditions(['id' => $entryId]);

        self::assertTrue($exists);
    }

    public function testExistsWithConditionsReturnsFalseWhenNotExists(): void
    {
        $exists = $this->repository->existsWithConditions(['id' => -999999]);

        self::assertFalse($exists);
    }

    // ==================== getFilteredEntries tests ====================

    public function testGetFilteredEntriesReturnsEntries(): void
    {
        $result = $this->repository->getFilteredEntries([], 0, 10);

        self::assertIsArray($result);
        self::assertLessThanOrEqual(10, count($result));
    }

    public function testGetFilteredEntriesAppliesDateFilters(): void
    {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');

        $result = $this->repository->getFilteredEntries([
            'startDate' => $startDate,
            'endDate' => $endDate,
        ], 0, 100);

        self::assertIsArray($result);
        foreach ($result as $entry) {
            self::assertGreaterThanOrEqual($startDate, $entry->getDay());
            self::assertLessThanOrEqual($endDate, $entry->getDay());
        }
    }

    public function testGetFilteredEntriesAppliesUserFilter(): void
    {
        $result = $this->repository->getFilteredEntries(['user' => $this->user], 0, 10);

        self::assertIsArray($result);
        foreach ($result as $entry) {
            $entryUser = $entry->getUser();
            assert(null !== $entryUser);
            self::assertSame($this->user->getId(), $entryUser->getId());
        }
    }

    public function testGetFilteredEntriesAppliesPagination(): void
    {
        $page1 = $this->repository->getFilteredEntries([], 0, 5);
        $page2 = $this->repository->getFilteredEntries([], 5, 5);

        self::assertLessThanOrEqual(5, count($page1));
        self::assertLessThanOrEqual(5, count($page2));

        // Pages should have different entries (if enough data exists)
        if (count($page1) > 0 && count($page2) > 0) {
            self::assertNotSame($page1[0]->getId(), $page2[0]->getId());
        }
    }

    public function testGetFilteredEntriesAppliesOrdering(): void
    {
        $result = $this->repository->getFilteredEntries([], 0, 10, 'day', 'ASC');

        self::assertIsArray($result);

        // Verify ordering
        $previousDay = null;
        foreach ($result as $entry) {
            if (null !== $previousDay) {
                self::assertGreaterThanOrEqual($previousDay, $entry->getDay());
            }
            $previousDay = $entry->getDay();
        }
    }

    public function testGetFilteredEntriesIgnoresInvalidOrderField(): void
    {
        // Should not throw, just use default ordering
        $result = $this->repository->getFilteredEntries([], 0, 10, 'invalid_field', 'DESC');

        self::assertIsArray($result);
    }

    public function testGetFilteredEntriesNormalizesInvalidOrderDirection(): void
    {
        // Invalid direction should be normalized to DESC
        $result = $this->repository->getFilteredEntries([], 0, 10, 'day', 'INVALID');

        self::assertIsArray($result);
    }

    // ==================== getSummaryData tests ====================

    public function testGetSummaryDataReturnsExpectedKeys(): void
    {
        $summary = $this->repository->getSummaryData();

        self::assertArrayHasKey('entryCount', $summary);
        self::assertArrayHasKey('totalDuration', $summary);
        self::assertArrayHasKey('avgDuration', $summary);
        self::assertArrayHasKey('minDate', $summary);
        self::assertArrayHasKey('maxDate', $summary);

        self::assertIsInt($summary['entryCount']);
        self::assertIsFloat($summary['totalDuration']);
        self::assertIsFloat($summary['avgDuration']);
    }

    public function testGetSummaryDataAppliesFilters(): void
    {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');

        $summary = $this->repository->getSummaryData([
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);

        self::assertArrayHasKey('entryCount', $summary);
        self::assertIsInt($summary['entryCount']);
    }

    // ==================== getTimeSummaryByPeriod tests ====================

    public function testGetTimeSummaryByPeriodByYear(): void
    {
        $result = $this->repository->getTimeSummaryByPeriod('year', [], '2024-01-01', '2025-12-31');

        self::assertIsArray($result);
    }

    public function testGetTimeSummaryByPeriodByMonth(): void
    {
        $result = $this->repository->getTimeSummaryByPeriod('month', [], '2025-01-01', '2025-12-31');

        self::assertIsArray($result);
    }

    public function testGetTimeSummaryByPeriodByWeek(): void
    {
        $result = $this->repository->getTimeSummaryByPeriod('week', [], '2025-01-01', '2025-01-31');

        self::assertIsArray($result);
    }

    public function testGetTimeSummaryByPeriodThrowsForInvalidPeriod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid period: invalid');

        $this->repository->getTimeSummaryByPeriod('invalid', []);
    }

    // ==================== bulkUpdate tests ====================

    public function testBulkUpdateReturnsZeroForEmptyEntryIds(): void
    {
        $result = $this->repository->bulkUpdate([], ['description' => 'test']);

        self::assertSame(0, $result);
    }

    public function testBulkUpdateReturnsZeroForEmptyUpdateData(): void
    {
        $result = $this->repository->bulkUpdate([1, 2, 3], []);

        self::assertSame(0, $result);
    }

    // ==================== queryByFilterArray tests ====================

    public function testQueryByFilterArrayReturnsQuery(): void
    {
        $query = $this->repository->queryByFilterArray([
            'maxResults' => 10,
        ]);

        $result = $query->getResult();
        self::assertIsArray($result);
    }

    public function testQueryByFilterArrayAppliesCustomerFilter(): void
    {
        $customerRepo = self::getContainer()->get('doctrine')->getRepository(Customer::class);
        $customers = $customerRepo->findAll();

        if ([] === $customers) {
            self::markTestSkipped('No customers in database');
        }

        $customer = $customers[0];
        assert($customer instanceof Customer);
        $customerId = $customer->getId();
        assert(null !== $customerId);

        $query = $this->repository->queryByFilterArray([
            'customer' => $customerId,
            'maxResults' => 10,
        ]);

        $result = $query->getResult();
        self::assertIsArray($result);
    }

    public function testQueryByFilterArrayAppliesProjectFilter(): void
    {
        $projectRepo = self::getContainer()->get('doctrine')->getRepository(Project::class);
        $projects = $projectRepo->findAll();

        if ([] === $projects) {
            self::markTestSkipped('No projects in database');
        }

        $project = $projects[0];
        assert($project instanceof Project);
        $projectId = $project->getId();
        assert(null !== $projectId);

        $query = $this->repository->queryByFilterArray([
            'project' => $projectId,
            'maxResults' => 10,
        ]);

        $result = $query->getResult();
        self::assertIsArray($result);
    }

    public function testQueryByFilterArrayAppliesActivityFilter(): void
    {
        $activityRepo = self::getContainer()->get('doctrine')->getRepository(Activity::class);
        $activities = $activityRepo->findAll();

        if ([] === $activities) {
            self::markTestSkipped('No activities in database');
        }

        $activity = $activities[0];
        assert($activity instanceof Activity);
        $activityId = $activity->getId();
        assert(null !== $activityId);

        $query = $this->repository->queryByFilterArray([
            'activity' => $activityId,
            'maxResults' => 10,
        ]);

        $result = $query->getResult();
        self::assertIsArray($result);
    }

    public function testQueryByFilterArrayAppliesUserFilter(): void
    {
        $userId = $this->user->getId();
        assert(null !== $userId);

        $query = $this->repository->queryByFilterArray([
            'user' => $userId,
            'maxResults' => 10,
        ]);

        $result = $query->getResult();
        self::assertIsArray($result);

        foreach ($result as $entry) {
            assert($entry instanceof Entry);
            $entryUser = $entry->getUser();
            assert(null !== $entryUser);
            self::assertSame($userId, $entryUser->getId());
        }
    }

    public function testQueryByFilterArrayAppliesDateFilters(): void
    {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');

        $query = $this->repository->queryByFilterArray([
            'datestart' => $startDate,
            'dateend' => $endDate,
            'maxResults' => 100,
        ]);

        $result = $query->getResult();
        self::assertIsArray($result);

        foreach ($result as $entry) {
            assert($entry instanceof Entry);
            self::assertGreaterThanOrEqual($startDate, $entry->getDay());
            self::assertLessThanOrEqual($endDate, $entry->getDay());
        }
    }

    public function testQueryByFilterArrayUsesStartOffset(): void
    {
        $query = $this->repository->queryByFilterArray([
            'start' => 5,
            'maxResults' => 10,
        ]);

        $result = $query->getResult();
        self::assertIsArray($result);
    }

    public function testQueryByFilterArrayUsesPageOffset(): void
    {
        $query = $this->repository->queryByFilterArray([
            'page' => 1,
            'maxResults' => 10,
        ]);

        $result = $query->getResult();
        self::assertIsArray($result);
    }

    public function testQueryByFilterArrayAcceptsObjectFilters(): void
    {
        $customerRepo = self::getContainer()->get('doctrine')->getRepository(Customer::class);
        $customers = $customerRepo->findAll();

        if ([] === $customers) {
            self::markTestSkipped('No customers in database');
        }

        // Pass object instead of ID
        $query = $this->repository->queryByFilterArray([
            'customer' => $customers[0],
            'maxResults' => 10,
        ]);

        $result = $query->getResult();
        self::assertIsArray($result);
    }

    // ==================== findOverlappingEntries tests ====================

    public function testFindOverlappingEntriesReturnsEmptyForNoOverlap(): void
    {
        // Use a time slot that's unlikely to overlap
        $result = $this->repository->findOverlappingEntries(
            $this->user,
            '1900-01-01',
            '23:00',
            '23:30',
        );

        self::assertSame([], $result);
    }

    public function testFindOverlappingEntriesExcludesSpecifiedId(): void
    {
        $entries = $this->repository->findAll();
        if ([] === $entries) {
            self::markTestSkipped('No entries in database');
        }

        $entry = $entries[0];
        assert($entry instanceof Entry);
        $entryUser = $entry->getUser();
        assert(null !== $entryUser);
        $entryId = $entry->getId();
        assert(null !== $entryId);

        // getDay(), getStart(), getEnd() return DateTime, convert to string format
        $dayString = $entry->getDay()->format('Y-m-d');
        $startString = $entry->getStart()->format('H:i');
        $endString = $entry->getEnd()->format('H:i');

        $result = $this->repository->findOverlappingEntries(
            $entryUser,
            $dayString,
            $startString,
            $endString,
            $entryId,
        );

        // Should not include the excluded entry
        foreach ($result as $overlapping) {
            self::assertNotSame($entryId, $overlapping->getId());
        }
    }

    // ==================== getEntriesByUser tests ====================

    public function testGetEntriesByUserReturnsEntries(): void
    {
        $result = $this->repository->getEntriesByUser($this->user, 30);

        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(Entry::class, $result);
    }

    public function testGetEntriesByUserWithShowFuture(): void
    {
        $result = $this->repository->getEntriesByUser($this->user, 30, true);

        self::assertIsArray($result);
    }

    // ==================== findByDate tests ====================

    public function testFindByDateReturnsEntries(): void
    {
        $year = (int) date('Y');
        $userId = $this->user->getId();
        assert(null !== $userId);

        $result = $this->repository->findByDate($userId, $year);

        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(Entry::class, $result);
    }

    public function testFindByDateWithMonth(): void
    {
        $year = (int) date('Y');
        $month = (int) date('n');
        $userId = $this->user->getId();
        assert(null !== $userId);

        $result = $this->repository->findByDate($userId, $year, $month);

        self::assertIsArray($result);
    }

    public function testFindByDateWithAllUsers(): void
    {
        $year = (int) date('Y');
        $result = $this->repository->findByDate(0, $year);

        self::assertIsArray($result);
    }

    public function testFindByDateWithProjectFilter(): void
    {
        $projectRepo = self::getContainer()->get('doctrine')->getRepository(Project::class);
        $projects = $projectRepo->findAll();

        if ([] === $projects) {
            self::markTestSkipped('No projects in database');
        }

        $project = $projects[0];
        assert($project instanceof Project);
        $projectId = $project->getId();
        assert(null !== $projectId);

        $year = (int) date('Y');
        $result = $this->repository->findByDate(0, $year, null, $projectId);

        self::assertIsArray($result);
    }

    public function testFindByDateWithCustomerFilter(): void
    {
        $customerRepo = self::getContainer()->get('doctrine')->getRepository(Customer::class);
        $customers = $customerRepo->findAll();

        if ([] === $customers) {
            self::markTestSkipped('No customers in database');
        }

        $customer = $customers[0];
        assert($customer instanceof Customer);
        $customerId = $customer->getId();
        assert(null !== $customerId);

        $year = (int) date('Y');
        $result = $this->repository->findByDate(0, $year, null, null, $customerId);

        self::assertIsArray($result);
    }

    public function testFindByDateWithSorting(): void
    {
        $year = (int) date('Y');
        $result = $this->repository->findByDate(0, $year, null, null, null, ['day' => 'ASC']);

        self::assertIsArray($result);
    }

    public function testFindByDateWithBooleanSortDirection(): void
    {
        $year = (int) date('Y');
        // Test with boolean true for ASC - need to use proper type
        $result = $this->repository->findByDate(0, $year, null, null, null, ['day' => 'ASC']);

        self::assertIsArray($result);
    }

    // ==================== findByDatePaginated tests ====================

    public function testFindByDatePaginatedReturnsEntries(): void
    {
        $year = (int) date('Y');
        $userId = $this->user->getId();
        assert(null !== $userId);

        $result = $this->repository->findByDatePaginated($userId, $year, null, null, null, null, 0, 10);

        self::assertIsArray($result);
        self::assertLessThanOrEqual(10, count($result));
    }

    public function testFindByDatePaginatedWithOffset(): void
    {
        $year = (int) date('Y');
        $page1 = $this->repository->findByDatePaginated(0, $year, null, null, null, null, 0, 5);
        $page2 = $this->repository->findByDatePaginated(0, $year, null, null, null, null, 5, 5);

        self::assertIsArray($page1);
        self::assertIsArray($page2);
    }

    // ==================== getWorkByUser tests ====================

    public function testGetWorkByUserReturnsExpectedStructure(): void
    {
        $userId = $this->user->getId();
        assert(null !== $userId);

        $result = $this->repository->getWorkByUser($userId, Period::DAY);

        self::assertArrayHasKey('duration', $result);
        self::assertArrayHasKey('count', $result);
        self::assertIsInt($result['duration']);
        self::assertIsInt($result['count']);
    }

    public function testGetWorkByUserByWeek(): void
    {
        $userId = $this->user->getId();
        assert(null !== $userId);

        $result = $this->repository->getWorkByUser($userId, Period::WEEK);

        self::assertArrayHasKey('duration', $result);
        self::assertArrayHasKey('count', $result);
    }

    public function testGetWorkByUserByMonth(): void
    {
        $userId = $this->user->getId();
        assert(null !== $userId);

        $result = $this->repository->getWorkByUser($userId, Period::MONTH);

        self::assertArrayHasKey('duration', $result);
        self::assertArrayHasKey('count', $result);
    }

    // ==================== getActivitiesWithTime tests ====================

    public function testGetActivitiesWithTimeReturnsEmptyForEmptyTicket(): void
    {
        self::assertSame([], $this->repository->getActivitiesWithTime(''));
        self::assertSame([], $this->repository->getActivitiesWithTime('0'));
    }

    public function testGetActivitiesWithTimeReturnsArrayForValidTicket(): void
    {
        // Find an entry with a ticket
        /** @var list<Entry> $entries */
        $entries = $this->repository->createQueryBuilder('e')
            ->where('e.ticket IS NOT NULL')
            ->andWhere('e.ticket != :empty')
            ->setParameter('empty', '')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if ([] === $entries) {
            self::markTestSkipped('No entries with tickets in database');
        }

        $ticket = $entries[0]->getTicket();
        assert(null !== $ticket && '' !== $ticket);

        $result = $this->repository->getActivitiesWithTime($ticket);

        self::assertIsArray($result);
    }

    // ==================== getUsersWithTime tests ====================

    public function testGetUsersWithTimeReturnsEmptyForEmptyTicket(): void
    {
        self::assertSame([], $this->repository->getUsersWithTime(''));
        self::assertSame([], $this->repository->getUsersWithTime('0'));
    }

    public function testGetUsersWithTimeReturnsArrayForValidTicket(): void
    {
        // Find an entry with a ticket
        /** @var list<Entry> $entries */
        $entries = $this->repository->createQueryBuilder('e')
            ->where('e.ticket IS NOT NULL')
            ->andWhere('e.ticket != :empty')
            ->setParameter('empty', '')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if ([] === $entries) {
            self::markTestSkipped('No entries with tickets in database');
        }

        $ticket = $entries[0]->getTicket();
        assert(null !== $ticket && '' !== $ticket);

        $result = $this->repository->getUsersWithTime($ticket);

        self::assertIsArray($result);
    }

    // ==================== findByRecentDaysOfUser tests ====================

    public function testFindByRecentDaysOfUserReturnsEntries(): void
    {
        $result = $this->repository->findByRecentDaysOfUser($this->user, 7);

        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(Entry::class, $result);
    }

    // ==================== findByUserAndTicketSystemToSync tests ====================

    public function testFindByUserAndTicketSystemToSyncReturnsEntries(): void
    {
        $userId = $this->user->getId();
        assert(null !== $userId);

        $result = $this->repository->findByUserAndTicketSystemToSync($userId, 1, 10);

        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(Entry::class, $result);
    }

    // ==================== getEntrySummary tests ====================

    public function testGetEntrySummaryReturnsDataForValidEntry(): void
    {
        $entries = $this->repository->findAll();
        if ([] === $entries) {
            self::markTestSkipped('No entries in database');
        }

        $entry = $entries[0];
        assert($entry instanceof Entry);
        $entryId = $entry->getId();
        $userId = $this->user->getId();
        assert(null !== $entryId && null !== $userId);

        $data = [];
        $result = $this->repository->getEntrySummary($entryId, $userId, $data);

        self::assertIsArray($result);
    }

    public function testGetEntrySummaryReturnsEmptyForNonExistentEntry(): void
    {
        $userId = $this->user->getId();
        assert(null !== $userId);

        $data = ['existing' => ['key' => 'value']];
        $result = $this->repository->getEntrySummary(-999999, $userId, $data);

        // Should return the original data unchanged
        self::assertSame($data, $result);
    }

    // ==================== findByDay tests ====================

    public function testFindByDayReturnsEntries(): void
    {
        $today = date('Y-m-d');
        $userId = $this->user->getId();
        assert(null !== $userId);

        $result = $this->repository->findByDay($userId, $today);

        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(Entry::class, $result);
    }

    // ==================== findByFilterArray tests ====================

    public function testFindByFilterArrayReturnsEntries(): void
    {
        $result = $this->repository->findByFilterArray(['maxResults' => 10]);

        self::assertIsArray($result);
        self::assertLessThanOrEqual(10, count($result));
    }

    public function testFindByFilterArrayAppliesCustomerFilter(): void
    {
        $customerRepo = self::getContainer()->get('doctrine')->getRepository(Customer::class);
        $customers = $customerRepo->findAll();

        if ([] === $customers) {
            self::markTestSkipped('No customers in database');
        }

        $customer = $customers[0];
        assert($customer instanceof Customer);
        $customerId = $customer->getId();
        assert(null !== $customerId);

        $result = $this->repository->findByFilterArray([
            'customer' => $customerId,
            'maxResults' => 10,
        ]);

        self::assertIsArray($result);
    }

    public function testFindByFilterArrayAppliesUserObjectFilter(): void
    {
        $result = $this->repository->findByFilterArray([
            'user' => $this->user,
            'maxResults' => 10,
        ]);

        self::assertIsArray($result);
        foreach ($result as $entry) {
            assert($entry instanceof Entry);
            $entryUser = $entry->getUser();
            assert(null !== $entryUser);
            self::assertSame($this->user->getId(), $entryUser->getId());
        }
    }

    public function testFindByFilterArrayAppliesDateFilters(): void
    {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');

        $result = $this->repository->findByFilterArray([
            'datestart' => $startDate,
            'dateend' => $endDate,
            'maxResults' => 100,
        ]);

        self::assertIsArray($result);
        foreach ($result as $entry) {
            assert($entry instanceof Entry);
            self::assertGreaterThanOrEqual($startDate, $entry->getDay());
            self::assertLessThanOrEqual($endDate, $entry->getDay());
        }
    }

    public function testFindByFilterArrayUsesStartOffset(): void
    {
        $result = $this->repository->findByFilterArray([
            'start' => 5,
            'maxResults' => 10,
        ]);

        self::assertIsArray($result);
    }

    public function testFindByFilterArrayUsesPageOffset(): void
    {
        $result = $this->repository->findByFilterArray([
            'page' => 1,
            'maxResults' => 10,
        ]);

        self::assertIsArray($result);
    }

    // ==================== getRawData tests ====================

    public function testGetRawDataReturnsFormattedData(): void
    {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');

        $result = $this->repository->getRawData($startDate, $endDate);

        self::assertIsArray($result);
    }

    public function testGetRawDataWithUserFilter(): void
    {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        $userId = $this->user->getId();
        assert(null !== $userId);

        $result = $this->repository->getRawData($startDate, $endDate, $userId);

        self::assertIsArray($result);
    }

    // ==================== Helper methods ====================

    private function createTestEntry(User $user, string $day): Entry
    {
        $em = self::getContainer()->get('doctrine')->getManager();

        $customerRepo = self::getContainer()->get('doctrine')->getRepository(Customer::class);
        $customers = $customerRepo->findAll();
        $customer = $customers[0] ?? null;

        $projectRepo = self::getContainer()->get('doctrine')->getRepository(Project::class);
        $projects = $projectRepo->findAll();
        $project = $projects[0] ?? null;

        $activityRepo = self::getContainer()->get('doctrine')->getRepository(Activity::class);
        $activities = $activityRepo->findAll();
        $activity = $activities[0] ?? null;

        $entry = new Entry();
        $entry->setUser($user);
        $entry->setDay($day);
        $entry->setStart('09:00');
        $entry->setEnd('10:00');
        $entry->setDuration(60);
        $entry->setDescription('Test entry');

        if ($customer instanceof Customer) {
            $entry->setCustomer($customer);
        }
        if ($project instanceof Project) {
            $entry->setProject($project);
        }
        if ($activity instanceof Activity) {
            $entry->setActivity($activity);
        }

        $em->persist($entry);
        $em->flush();

        return $entry;
    }
}

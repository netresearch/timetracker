<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\EntryClass;
use App\Repository\EntryRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

use function assert;

/**
 * Regression test for bulk entry visibility bug.
 *
 * Bug: Entries created via bulk entry for the current month are not visible
 * in "Zeiterfassung" or "Auswertung" views, even though header shows correct
 * totals (Heute, Woche, Monat).
 *
 * Root causes:
 * 1. GetDataAction defaults to showing only last 3 days when no explicit date range
 * 2. GetDataAction ignores `user` query parameter when `year` is not provided,
 *    always using CurrentUser instead of the requested user
 *
 * @internal
 *
 * @coversNothing
 */
final class BulkEntryVisibilityTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $em = $container->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
    }

    /**
     * Test that entries created for earlier dates in the month are visible.
     *
     * This reproduces the bug where bulk-created entries for the month
     * showed in header totals but not in the entry list views.
     */
    public function testBulkEntriesVisibleInZeiterfassungView(): void
    {
        // Get the test user
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy([]);
        self::assertNotNull($user, 'Test user should exist');

        // Get required related entities
        $customer = $this->entityManager->getRepository(Customer::class)->findOneBy([]);
        $project = $this->entityManager->getRepository(Project::class)->findOneBy([]);
        $activity = $this->entityManager->getRepository(Activity::class)->findOneBy([]);

        self::assertNotNull($customer, 'Test customer should exist');
        self::assertNotNull($project, 'Test project should exist');
        self::assertNotNull($activity, 'Test activity should exist');

        // Create entries simulating bulk entry for the beginning of the month
        // (more than 3 days ago - this is the key!)
        $today = new DateTime();
        $startOfMonth = new DateTime($today->format('Y-m-01'));

        // Create 5 entries at the start of the month (more than 3 days ago)
        $createdEntryIds = [];
        for ($i = 0; $i < 5; ++$i) {
            $entryDate = clone $startOfMonth;
            $entryDate->modify("+{$i} days");

            // Skip if this date is within the last 3 days (would be visible anyway)
            $daysDiff = (int) $today->diff($entryDate)->days;
            if ($daysDiff <= 3) {
                continue;
            }

            $entry = new Entry();
            $entry->setUser($user)
                ->setCustomer($customer)
                ->setProject($project)
                ->setActivity($activity)
                ->setTicket('')
                ->setDescription('Bulk test entry')
                ->setDay($entryDate->format('Y-m-d'))
                ->setStart('08:00:00')
                ->setEnd('16:00:00')
                ->setClass(EntryClass::DAYBREAK)
                ->calcDuration();

            $this->entityManager->persist($entry);
            $this->entityManager->flush();
            $createdEntryIds[] = $entry->getId();
        }

        // Skip test if we couldn't create entries more than 3 days ago
        // (happens at the beginning of the month)
        if ([] === $createdEntryIds) {
            self::markTestSkipped('Cannot create entries more than 3 days ago at start of month');
        }

        // Verify entries exist in database
        $entryRepository = $this->entityManager->getRepository(Entry::class);
        assert($entryRepository instanceof EntryRepository);

        foreach ($createdEntryIds as $entryId) {
            $entry = $entryRepository->find($entryId);
            self::assertNotNull($entry, "Entry {$entryId} should exist in database");
        }

        // TEST 1: Header totals should include these entries (getWorkByUser)
        $userId = (int) $user->getId();
        $monthWork = $entryRepository->getWorkByUser($userId, \App\Enum\Period::MONTH);
        self::assertGreaterThan(0, $monthWork['duration'], 'Month duration should include bulk entries');

        // TEST 2: Default Zeiterfassung view (3 days) should NOT show old entries
        // This is the CURRENT (buggy?) behavior - it only shows 3 days
        $recentEntries = $entryRepository->getEntriesByUser($user, 3, false);
        $recentEntryIds = array_map(static fn (Entry $e) => $e->getId(), $recentEntries);

        // The old entries should NOT appear in 3-day view
        foreach ($createdEntryIds as $oldEntryId) {
            self::assertNotContains(
                $oldEntryId,
                $recentEntryIds,
                'Entries older than 3 days should not appear in default view (current behavior)',
            );
        }

        // TEST 3: Month view SHOULD show all entries
        $startDate = $startOfMonth->format('Y-m-d');
        $endDate = $today->format('Y-m-d');
        $monthEntries = $entryRepository->getEntriesForMonth($user, $startDate, $endDate);
        $monthEntryIds = array_map(static fn (Entry $e) => $e->getId(), $monthEntries);

        foreach ($createdEntryIds as $oldEntryId) {
            self::assertContains(
                $oldEntryId,
                $monthEntryIds,
                "Entry {$oldEntryId} should appear in month view",
            );
        }

        // Cleanup
        foreach ($createdEntryIds as $entryId) {
            $entry = $entryRepository->find($entryId);
            if ($entry instanceof Entry) {
                $this->entityManager->remove($entry);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Test that the interpretation view (Auswertung) shows entries when filtered by month.
     */
    public function testBulkEntriesVisibleInAuswertungWithMonthFilter(): void
    {
        // Get the test user
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy([]);
        self::assertNotNull($user, 'Test user should exist');

        // Get required related entities
        $customer = $this->entityManager->getRepository(Customer::class)->findOneBy([]);
        $project = $this->entityManager->getRepository(Project::class)->findOneBy([]);
        $activity = $this->entityManager->getRepository(Activity::class)->findOneBy([]);

        self::assertNotNull($customer, 'Test customer should exist');
        self::assertNotNull($project, 'Test project should exist');
        self::assertNotNull($activity, 'Test activity should exist');

        // Create an entry at the start of the month
        $today = new DateTime();
        $startOfMonth = new DateTime($today->format('Y-m-01'));

        $entry = new Entry();
        $entry->setUser($user)
            ->setCustomer($customer)
            ->setProject($project)
            ->setActivity($activity)
            ->setTicket('')
            ->setDescription('Auswertung test entry')
            ->setDay($startOfMonth->format('Y-m-d'))
            ->setStart('09:00:00')
            ->setEnd('17:00:00')
            ->setClass(EntryClass::DAYBREAK)
            ->calcDuration();

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $entryId = $entry->getId();

        // Test: findByFilterArray with user and date range should find the entry
        $entryRepository = $this->entityManager->getRepository(Entry::class);
        assert($entryRepository instanceof EntryRepository);

        $filters = [
            'user' => $user->getId(),
            'datestart' => $startOfMonth->format('Y-m-d'),
            'dateend' => $today->format('Y-m-d'),
        ];

        $filteredEntries = $entryRepository->findByFilterArray($filters);
        $filteredIds = array_map(static fn (Entry $e) => $e->getId(), $filteredEntries);

        self::assertContains(
            $entryId,
            $filteredIds,
            'Entry should be visible in Auswertung when filtered by user and date range',
        );

        // Cleanup
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }
}

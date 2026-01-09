<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\EntryRepository;
use Tests\AbstractWebTestCase;

use function count;

/**
 * @internal
 *
 * @coversNothing
 */
final class EntryRepositoryIntegrationTest extends AbstractWebTestCase
{
    public function testFindByRecentDaysOfUserReturnsExpected(): void
    {
        $repo = self::getContainer()->get('doctrine')->getRepository(\App\Entity\Entry::class);
        assert($repo instanceof EntryRepository);

        // Get the User entity from the repository instead of passing raw ID
        $userRepository = self::getContainer()->get('doctrine')->getRepository(\App\Entity\User::class);
        $user = $userRepository->find(1);
        self::assertNotNull($user, 'User with ID 1 should exist');

        // Use a date range to test the getEntriesForMonth method
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-3 days'));
        $data = $repo->getEntriesForMonth($user, $startDate, $endDate);
        self::assertIsArray($data);

        // Verify each entry in the result is an Entry entity
        foreach ($data as $entry) {
            self::assertInstanceOf(\App\Entity\Entry::class, $entry);
            self::assertSame($user, $entry->getUser());
        }
    }

    public function testFindByFilterArrayBasicFilters(): void
    {
        $repo = self::getContainer()->get('doctrine')->getRepository(\App\Entity\Entry::class);
        assert($repo instanceof EntryRepository);

        // Use the queryByFilterArray method instead
        $query = $repo->queryByFilterArray([
            'customer' => 1,
            'project' => 1,
            'maxResults' => 5,
        ]);
        $result = $query->getResult();

        self::assertIsArray($result);
        self::assertLessThanOrEqual(5, count($result));
    }
}

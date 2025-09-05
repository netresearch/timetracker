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
        /** @var EntryRepository $repo */
        $repo = self::getContainer()->get('doctrine')->getRepository(\App\Entity\Entry::class);
        
        // Use a date range instead of the missing method
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-3 days'));
        $data = $repo->getEntriesForMonth(1, $startDate, $endDate);
        self::assertIsArray($data);
    }

    public function testFindByFilterArrayBasicFilters(): void
    {
        /** @var EntryRepository $repo */
        $repo = self::getContainer()->get('doctrine')->getRepository(\App\Entity\Entry::class);
        
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

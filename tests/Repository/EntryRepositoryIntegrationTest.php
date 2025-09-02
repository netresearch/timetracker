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
        $user = self::getContainer()->get('doctrine')->getRepository(\App\Entity\User::class)->find(1);
        $data = $repo->findByRecentDaysOfUser($user, 3);
        self::assertIsArray($data);
    }

    public function testFindByFilterArrayBasicFilters(): void
    {
        /** @var EntryRepository $repo */
        $repo = self::getContainer()->get('doctrine')->getRepository(\App\Entity\Entry::class);
        $result = $repo->findByFilterArray([
            'user' => 1,
            'customer' => 1,
            'project' => 1,
            'maxResults' => 5,
        ]);
        self::assertIsArray($result);
        self::assertLessThanOrEqual(5, count($result));
    }
}

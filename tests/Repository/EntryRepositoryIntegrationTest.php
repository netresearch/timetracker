<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\EntryRepository;
use Tests\AbstractWebTestCase;

class EntryRepositoryIntegrationTest extends AbstractWebTestCase
{
    public function testFindByRecentDaysOfUserReturnsExpected(): void
    {
        /** @var EntryRepository $repo */
        $repo = static::getContainer()->get('doctrine')->getRepository(\App\Entity\Entry::class);
        $user = static::getContainer()->get('doctrine')->getRepository(\App\Entity\User::class)->find(1);
        $data = $repo->findByRecentDaysOfUser($user, 3);
        $this->assertIsArray($data);
    }

    public function testFindByFilterArrayBasicFilters(): void
    {
        /** @var EntryRepository $repo */
        $repo = static::getContainer()->get('doctrine')->getRepository(\App\Entity\Entry::class);
        $result = $repo->findByFilterArray([
            'user' => 1,
            'customer' => 1,
            'project' => 1,
            'maxResults' => 5,
        ]);
        $this->assertIsArray($result);
        $this->assertLessThanOrEqual(5, count($result));
    }
}



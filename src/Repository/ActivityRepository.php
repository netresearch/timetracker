<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activity>
 */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Activity::class);
    }

    /**
     * Priority 2: Add explicit type-safe repository method for mixed type handling.
     */
    public function findOneById(int $id): ?Activity
    {
        $result = $this->find($id);
        
        return $result instanceof Activity ? $result : null;
    }

    /**
     * @return array<int, array{activity: array{id:int, name:string, needsTicket:bool, factor:float|string}}>
     */
    public function getActivities(): array
    {
        /** @var Activity[] $activities */
        $activities = $this->findBy([], ['name' => 'ASC']);

        $data = [];
        foreach ($activities as $activity) {
            $data[] = ['activity' => [
                'id' => (int) $activity->getId(),
                'name' => (string) $activity->getName(),
                'needsTicket' => (bool) $activity->getNeedsTicket(),
                'factor' => $activity->getFactor(),
            ]];
        }

        return $data;
    }

    public function findOneByName(string $name): ?Activity
    {
        $result = $this->findOneBy(['name' => $name]);

        return $result instanceof Activity ? $result : null;
    }
}
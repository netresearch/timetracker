<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\Activity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ActivityRepository extends ServiceEntityRepository
{
    /**
     * ActivityRepository constructor.
     */
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Activity::class);
    }

    /**
     * @return array[] Activities sorted by name
     */
    public function getActivities(): array
    {
        /** @var Activity[] $activities */
        $activities = $this->findBy([], ['name' => 'ASC']);

        $data = [];
        foreach ($activities as $activity) {
            $data[] = ['activity' => [
                'id'          => $activity->getId(),
                'name'        => $activity->getName(),
                'needsTicket' => $activity->getNeedsTicket(),
                'factor'      => $activity->getFactor(),
            ]];
        }

        return $data;
    }
}

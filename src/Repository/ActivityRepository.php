<?php

namespace App\Repository;

use App\Entity\Activity;
use Doctrine\ORM\EntityRepository;

class ActivityRepository extends EntityRepository
{
    /**
     * @return array[] Activities sorted by name
     */
    public function getActivities()
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

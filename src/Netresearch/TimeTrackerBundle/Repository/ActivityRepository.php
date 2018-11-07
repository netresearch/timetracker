<?php

namespace Netresearch\TimeTrackerBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Netresearch\TimeTrackerBundle\Entity\Activity;

class ActivityRepository extends EntityRepository
{
    /**
     * @return array[] Activities sorted by name
     */
    public function getActivities()
    {
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

<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\EntityRepository;

class ActivityRepository extends EntityRepository
{
    /*
     * Find all activities, sorted ascending
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

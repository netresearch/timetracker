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
        $activities = $this->findBy(array(), array('name' => 'ASC'));
        
        $data = array();
        foreach ($activities as $activity) {
            $data[] = array('activity' => array(
                'id'            => $activity->getId(),
                'name'          => $activity->getName(),
                'needsTicket'   => $activity->getNeedsTicket(),
                'factor'        => $activity->getFactor()
            ));
        }
        
        return $data;
    }
}

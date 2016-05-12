<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Netresearch\TimeTrackerBundle\Controller\DefaultController;

use Doctrine\ORM\EntityRepository;

class PresetRepository extends EntityRepository
{
    
    /**
     * get all presets
     * 
     * @return array
     */
    public function getAllPresets()
    {
        $presets = $this->findBy(array(), array('name' => 'ASC'));
        
        $data = array();
        foreach ($presets as $preset) {
            $data[] = array('preset' => $preset->toArray());
        }
        
        return $data;
    }
}


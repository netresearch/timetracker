<?php

namespace Netresearch\TimeTrackerBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Netresearch\TimeTrackerBundle\Entity\Preset;

class PresetRepository extends EntityRepository
{

    /**
     * get all presets
     *
     * @return array
     */
    public function getAllPresets()
    {
        /** @var Preset[] $presets */
        $presets = $this->findBy(array(), array('name' => 'ASC'));

        $data = array();
        foreach ($presets as $preset) {
            $data[] = array('preset' => $preset->toArray());
        }

        return $data;
    }
}


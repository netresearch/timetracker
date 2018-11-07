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
        $presets = $this->findBy([], ['name' => 'ASC']);

        $data = [];
        foreach ($presets as $preset) {
            $data[] = ['preset' => $preset->toArray()];
        }

        return $data;
    }
}


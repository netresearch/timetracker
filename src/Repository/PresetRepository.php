<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\Preset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PresetRepository extends ServiceEntityRepository
{
    /**
     * PresetRepository constructor.
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Preset::class);
    }

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


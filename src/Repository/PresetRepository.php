<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Preset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Preset>
 */
class PresetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Preset::class);
    }

    /**
     * @return array<int, array{preset: array<string, mixed>}>
     */
    public function getAllPresets(): array
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

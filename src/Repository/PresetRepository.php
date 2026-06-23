<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

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

    /**
     * Presets the given user may use: those whose customer is global or belongs
     * to one of the user's teams. Mirrors CustomerRepository::getCustomersByUser
     * so bulk entry never exposes presets tied to team-restricted customers the
     * user cannot otherwise see. Admins use getAllPresets() for management.
     *
     * @return array<int, array{preset: array<string, mixed>}>
     */
    public function getPresetsByUser(int $userId): array
    {
        /** @var Preset[] $presets */
        $presets = $this->createQueryBuilder('preset')
            ->leftJoin('preset.customer', 'customer')
            ->leftJoin('customer.teams', 'team')
            ->leftJoin('team.users', 'user')
            ->andWhere('customer.global = 1')
            ->orWhere('user.id = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('preset.name', 'ASC')
            ->distinct()
            ->getQuery()
            ->getResult();

        $data = [];
        foreach ($presets as $preset) {
            $data[] = ['preset' => $preset->toArray()];
        }

        return $data;
    }
}

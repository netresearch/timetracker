<?php

namespace App\Repository;

use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TeamRepository extends ServiceEntityRepository
{
    /**
     * TeamRepository constructor.
     */
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Team::class);
    }

    /**
     * @return array[]
     */
    public function findAll()
    {
        /** @var Team[] $teams */
        $teams = parent::findBy([], ['name' => 'ASC']);
        $data = [];
        foreach ($teams as $team) {
            $data[] = ['team' => [
                'id'           => $team->getId(),
                'name'         => $team->getName(),
                'lead_user_id' => $team->getLeadUser()->getId(),
            ]];
        }

        return $data;
    }

    /**
     * Returns teams as Doctrine entities, sorted by name.
     * Note: use this instead of overriding findAll() for type safety.
     *
     * @return Team[]
     */
    public function getAllTeams(): array
    {
        return parent::findBy([], ['name' => 'ASC']);
    }

    public function findOneByName(string $name): ?Team
    {
        return $this->findOneBy(['name' => $name]);
    }
}

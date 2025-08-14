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

    // Do not override findAll(); it should return Team[] as in the parent

    /**
     * Returns teams as Doctrine entities, sorted by name.
     * Note: use this instead of overriding findAll() for type safety.
     *
     * @return Team[]
     */
    public function getAllTeams(): array
    {
        /** @var array $result */
        $result = parent::findBy([], ['name' => 'ASC']);
        return $result;
    }

    /**
     * Returns teams as array formatted for API responses.
     *
     * @return array<int, array{team: array{id: int, name: string, lead_user_id: int}}>
     */
    public function getAllTeamsAsArray(): array
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

    public function findOneByName(string $name): ?Team
    {
        return $this->findOneBy(['name' => $name]);
    }
}

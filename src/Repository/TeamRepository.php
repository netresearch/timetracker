<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<\App\Entity\Team>
 */
class TeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Team::class);
    }

    // Do not override findAll(); it should return Team[] as in the parent

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
                'id' => (int) ($team->getId() ?? 0),
                'name' => (string) ($team->getName() ?? ''),
                'lead_user_id' => (int) ($team->getLeadUser() ? $team->getLeadUser()->getId() : 0),
            ]];
        }

        return $data;
    }

    public function findOneByName(string $name): ?Team
    {
        $result = $this->findOneBy(['name' => $name]);

        return $result instanceof Team ? $result : null;
    }
}

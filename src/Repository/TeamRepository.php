<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TeamRepository extends ServiceEntityRepository
{
    /**
     * TeamRepository constructor.
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Team::class);
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
}

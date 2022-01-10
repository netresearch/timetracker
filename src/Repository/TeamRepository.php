<?php declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\Team;

class TeamRepository extends EntityRepository
{
    /**
     * @return array[]
     */
    public function findAll()
    {
        /** @var Team[] $teams */
        $teams = $this->findBy([], ['name' => 'ASC']);
        $data  = [];
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

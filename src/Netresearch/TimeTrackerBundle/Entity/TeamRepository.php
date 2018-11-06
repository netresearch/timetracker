<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\EntityRepository;

class TeamRepository extends EntityRepository
{
    /**
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     */
    public function findAll()
    {
        $connection = $this->getEntityManager()->getConnection();

        $stmt = $connection->query("SELECT DISTINCT c.id, c.name, c.lead_user_id FROM teams c ORDER BY name ASC;");

        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $data = array();
        if (count($result)) {
            foreach ($result as $line) {
                $data[] = array('team' => array(
                    'id'           => $line['id'],
                    'name'         => $line['name'],
                    'lead_user_id' => $line['lead_user_id'],
                ));
            }
        }

        return $data;
    }
}

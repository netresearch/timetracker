<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\EntityRepository;

class CustomerRepository extends EntityRepository
{
    /*
     * Returns an array of customers available for current user
     */
    public function getCustomersByUser($userId)
    {
        $connection = $this->getEntityManager()->getConnection();

        /* Creepy */
        $stmt = $connection->query("SELECT DISTINCT c.id, c.name, c.active"
            ." FROM customers c"
            ." LEFT JOIN teams_customers tc"
            ." ON tc.customer_id = c.id"
            ." LEFT JOIN teams_users tu"
            ." ON tc.team_id = tu.team_id"
            ." WHERE (c.global=1 OR tu.user_id = " . $userId . ")"
            ." ORDER BY name ASC;");

        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $data = array();
        if (count($result)) foreach ($result as $line) {
            $data[] = array('customer' => array(
                'id'        => $line['id'],
                'name'      => $line['name'],
                'active'    => $line['active'],
            ));
        }

        return $data;
    }

    /*
     * Returns an array of all available customers
     */
    public function getAllCustomers()
    {
        $connection = $this->getEntityManager()->getConnection();

        /* Creepy */
        $stmt = $connection->query("
            SELECT DISTINCT c.id, c.name, c.active, c.global, GROUP_CONCAT(tc.team_id) AS teams
            FROM customers c LEFT JOIN teams_customers tc ON c.id = tc.customer_id
            GROUP BY c.id ORDER BY name ASC;");

        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $data = array();
        if (count($result)) {
            foreach ($result as $line) {
                $data[] = array('customer' => array(
                    'id'     => $line['id'],
                    'name'   => $line['name'],
                    'active' => $line['active'],
                    'global' => $line['global'],
                    'teams'  => explode(',', $line['teams']),
                ));
            }
        }

        return $data;
    }
}

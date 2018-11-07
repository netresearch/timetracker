<?php

namespace Netresearch\TimeTrackerBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Netresearch\TimeTrackerBundle\Entity\Customer;

class CustomerRepository extends EntityRepository
{
    /**
     * Returns an array of customers available for current user
     *
     * @param $userId
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getCustomersByUser($userId)
    {
        $connection = $this->getEntityManager()->getConnection();

        /* Creepy */
        $stmt = $connection->query("
            SELECT DISTINCT c.id, c.name, c.active
            FROM customers c
            LEFT JOIN teams_customers tc
            ON tc.customer_id = c.id
            LEFT JOIN teams_users tu
            ON tc.team_id = tu.team_id
            WHERE (c.global=1 OR tu.user_id = " . $userId . ")
            ORDER BY name ASC;");

        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $data = [];
        if (count($result)) foreach ($result as $line) {
            $data[] = ['customer' => [
                'id'     => $line['id'],
                'name'   => $line['name'],
                'active' => $line['active'],
            ]];
        }

        return $data;
    }

    /*
     * Returns an array of all available customers
     */
    public function getAllCustomers()
    {
        /** @var Customer[] $customers */
        $customers = $this->findBy(
            [], ['name' => 'ASC']
        );

        $data = [];
        foreach ($customers as $customer) {
            $teams = [];
            foreach ($customer->getTeams() as $team) {
                $teams[] = $team->getId();
            }
            $data[] = ['customer' => [
                'id'     => $customer->getId(),
                'name'   => $customer->getName(),
                'active' => $customer->getActive(),
                'global' => $customer->getGlobal(),
                'teams'  => $teams,
            ]];
        }

        return $data;
    }
}

<?php declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\Customer;

class CustomerRepository extends EntityRepository
{
    /**
     * Returns an array of customers available for current user.
     */
    public function getCustomersByUser(int $userId): array
    {
        /** @var Customer[] $result */
        $result = $this->createQueryBuilder('customer')
            ->andWhere('customer.global = :global')
            ->setParameter('global', true)
            ->orWhere('user.id = :userId')
            ->setParameter('userId', $userId)
            ->leftJoin('customer.teams', 'team')
            ->leftJoin('team.users', 'user')
            ->getQuery()
            ->execute()
        ;

        $data = [];
        foreach ($result as $customer) {
            $data[] = ['customer' => [
                'id'     => $customer->getId(),
                'name'   => $customer->getName(),
                'active' => $customer->getActive(),
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
            [],
            ['name' => 'ASC']
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

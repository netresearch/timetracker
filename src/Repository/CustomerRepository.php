<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Customer::class);
    }

    /**
     * Returns an array of customers available for current user
     *
     * @param $userId
     */
    public function getCustomersByUser($userId): array
    {
        /** @var Customer[] $result */
        $result = $this->createQueryBuilder('customer')
            ->andWhere('customer.global = 1')
            ->orWhere('user.id = :userId')
            ->setParameter('userId', $userId)
            ->leftJoin('customer.teams', 'team')
            ->leftJoin('team.users', 'user')
            ->getQuery()
            ->execute();

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
    /**
     * @return array{customer: array{id: mixed, name: mixed, active: mixed, global: mixed, teams: list}}[]
     */
    public function getAllCustomers(): array
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

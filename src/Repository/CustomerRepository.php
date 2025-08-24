<?php

namespace App\Repository;

use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }
    /**
     * Returns an array of customers available for current user.
     */
    /**
     * @return array<int, array{customer: array{id:int, name:string, active:bool}}>
     */
    public function getCustomersByUser(int $userId): array
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
                'id' => (int) $customer->getId(),
                'name' => (string) $customer->getName(),
                'active' => (bool) $customer->getActive(),
            ]];
        }

        return $data;
    }

    /*
     * Returns an array of all available customers
     */
    /**
     * @return array<int, array{customer: array{id:int, name:string, active:bool, global:bool, teams: array<int, int>}}>
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
                $teams[] = (int) $team->getId();
            }

            $data[] = ['customer' => [
                'id' => (int) $customer->getId(),
                'name' => (string) $customer->getName(),
                'active' => (bool) $customer->getActive(),
                'global' => (bool) $customer->getGlobal(),
                'teams' => $teams,
            ]];
        }

        return $data;
    }

    public function findOneByName(string $name): ?Customer
    {
        $result = $this->findOneBy(['name' => $name]);

        return $result instanceof Customer ? $result : null;
    }
}

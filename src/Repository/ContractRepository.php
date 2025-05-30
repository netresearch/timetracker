<?php
namespace App\Repository;

use App\Entity\Contract;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Database with all contracts
 *
 * @author Tony Kreissl <kreissl@mogic.com>
 */
class ContractRepository extends ServiceEntityRepository
{
    /**
     * ContractRepository constructor.
     */
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Contract::class);
    }

    /**
     * @return Contract[]
     */
    public function findAll(): array
    {
        return $this->createQueryBuilder('contracts')
            ->join('contracts.user', 'users')
            ->orderBy('users.username', 'ASC')
            ->addOrderBy('contracts.start', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

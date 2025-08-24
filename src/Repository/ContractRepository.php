<?php

namespace App\Repository;

use App\Entity\Contract;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Database with all contracts.
 *
 * @author Tony Kreissl <kreissl@mogic.com>
 */
class ContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contract::class);
    }
    /**
     * Find all contracts, sorted by start ascending.
     *
     * @return array<int, array{contract: array{id:int, user_id:int, start:string|null, end:string|null, hours_0:float, hours_1:float, hours_2:float, hours_3:float, hours_4:float, hours_5:float, hours_6:float}}>
     */
    public function getContracts(): array
    {
        /** @var \Doctrine\ORM\QueryBuilder $queryBuilder */
        $queryBuilder = $this->createQueryBuilder('contracts')
            ->join('contracts.user', 'users')
            ->orderBy('users.username', 'ASC')
            ->addOrderBy('contracts.start', 'ASC');

        /** @var \Doctrine\ORM\Query $query */
        $query = $queryBuilder->getQuery();
        /** @var Contract[] $contracts */
        $contracts = $query->getResult();
        $data = [];

        /** @var Contract $contract */
        foreach ($contracts as $contract) {
            $data[] = ['contract' => [
                'id' => (int) $contract->getId(),
                'user_id' => (int) $contract->getUser()->getId(),
                'start' => $contract->getStart()
                    ? $contract->getStart()->format('Y-m-d')
                    : null,
                'end' => $contract->getEnd()
                    ? $contract->getEnd()->format('Y-m-d')
                    : null,
                'hours_0' => (float) $contract->getHours0(),
                'hours_1' => (float) $contract->getHours1(),
                'hours_2' => (float) $contract->getHours2(),
                'hours_3' => (float) $contract->getHours3(),
                'hours_4' => (float) $contract->getHours4(),
                'hours_5' => (float) $contract->getHours5(),
                'hours_6' => (float) $contract->getHours6(),
            ]];
        }

        return $data;
    }
}

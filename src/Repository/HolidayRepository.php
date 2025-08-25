<?php

namespace App\Repository;

use App\Entity\Holiday;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<\App\Entity\Holiday>
 */
class HolidayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Holiday::class);
    }
    /**
     * get all holidays in a given year and month.
     *
     * @return array<int, Holiday>
     */
    public function findByMonth(int $year, int $month): array
    {
        $from = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
        $to = (clone $from)->modify('first day of next month');

        return $this->createQueryBuilder('h')
            ->where('h.day >= :from')
            ->andWhere('h.day < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('h.day', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

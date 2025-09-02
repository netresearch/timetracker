<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Holiday;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function sprintf;

/**
 * @extends ServiceEntityRepository<\App\Entity\Holiday>
 */
class HolidayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Holiday::class);
    }

    /**
     * get all holidays in a given year and month.
     *
     * @return array<int, Holiday>
     */
    public function findByMonth(int $year, int $month): array
    {
        $from = new DateTime(sprintf('%04d-%02d-01', $year, $month));
        $to = (clone $from)->modify('first day of next month');

        /** @var array<int, Holiday> */
        return $this->createQueryBuilder('h')
            ->where('h.day >= :from')
            ->andWhere('h.day < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('h.day', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }
}

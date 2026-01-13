<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Holiday;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function assert;
use function is_array;
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
     * @return list<Holiday>
     */
    public function findByMonth(int $year, int $month): array
    {
        $from = new DateTime(sprintf('%04d-%02d-01', $year, $month));
        $to = (clone $from)->modify('first day of next month');

        $result = $this->createQueryBuilder('h')
            ->where('h.day >= :from')
            ->andWhere('h.day < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('h.day', 'ASC')
            ->getQuery()
            ->getResult();

        assert(is_array($result) && array_is_list($result));
        /** @var list<Holiday> $result */

        return $result;
    }
}

<?php

namespace App\Repository;

use App\Entity\Holiday;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class HolidayRepository extends ServiceEntityRepository
{
    /**
     * HolidayRepository constructor.
     */
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Holiday::class);
    }

    /**
     * get all holidays in a given year and month
     *
     * @param int $year
     * @param int $month
     * @return array
     */
    public function findByMonth($year, $month)
    {
        $entityManager = $this->getEntityManager();

        $pattern = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . '%';

        $query = $entityManager->createQuery(
            'SELECT h FROM App\Entity\Holiday h'
            . ' WHERE h.day LIKE :month'
            . ' ORDER BY h.day ASC'
        )->setParameter('month', $pattern);

        return $query->getResult();
    }
}

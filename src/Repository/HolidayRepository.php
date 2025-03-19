<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\Holiday;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class HolidayRepository extends ServiceEntityRepository
{
    /**
     * HolidayRepository constructor.
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Holiday::class);
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
        $em = $this->getEntityManager();

        $pattern = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . '%';

        $query = $em->createQuery(
            'SELECT h FROM App\Entity\Holiday h'
            . ' WHERE h.day LIKE :month'
            . ' ORDER BY h.day ASC'
        )->setParameter('month', $pattern);

        return $query->getResult();
    }
}


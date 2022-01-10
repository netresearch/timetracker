<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;

class HolidayRepository extends EntityRepository
{
    /**
     * get all holidays in a given year and month.
     *
     * @param int $year
     * @param int $month
     *
     * @return array
     */
    public function findByMonth($year, $month)
    {
        $em = $this->getEntityManager();

        $pattern = $year.'-'.str_pad($month, 2, '0', STR_PAD_LEFT).'-'.'%';

        $query = $em->createQuery(
            'SELECT h FROM App:Holiday h'
            .' WHERE h.day LIKE :month'
            .' ORDER BY h.day ASC'
        )->setParameter('month', $pattern);

        return $query->getResult();
    }
}

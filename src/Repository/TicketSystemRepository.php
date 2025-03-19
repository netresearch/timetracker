<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\TicketSystem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TicketSystemRepository extends ServiceEntityRepository
{
    /**
     * TicketSystemRepository constructor.
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketSystem::class);
    }

    /**
     * get all ticket systems
     *
     * @return array
     * @throws \ReflectionException
     */
    public function getAllTicketSystems()
    {
        /** @var TicketSystem[] $systems */
        $systems = $this->findBy([], ['name' => 'ASC']);

        $data = [];
        foreach ($systems as $system) {
            $data[] = ['ticketSystem' => $system->toArray()];
        }

        return $data;
    }

}

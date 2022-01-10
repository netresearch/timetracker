<?php

namespace App\Repository;

use ReflectionException;
use Doctrine\ORM\EntityRepository;
use App\Entity\TicketSystem;

class TicketSystemRepository extends EntityRepository
{
    /**
     * get all ticket systems.
     *
     * @throws ReflectionException
     *
     * @return array
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

<?php

namespace Netresearch\TimeTrackerBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Netresearch\TimeTrackerBundle\Entity\TicketSystem;

class TicketSystemRepository extends EntityRepository
{

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

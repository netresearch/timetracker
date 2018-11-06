<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\EntityRepository;

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
        $systems = $this->findBy(array(), array('name' => 'ASC'));

        $data = array();
        foreach ($systems as $system) {
            $data[] = array('ticketSystem' => $system->toArray());
        }

        return $data;
    }

}

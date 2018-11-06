<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\EntityRepository;

class TicketRepository extends EntityRepository
{
    /**
     * Returns a single ticket object if possible, otherwise null
     *
     * @param TicketSystem $ticketSystem
     * @param string $ticketNumber
     *
     * @return Ticket $ticket
     */
    public function findByTicketNumber(TicketSystem $ticketSystem, $ticketNumber)
    {
        $query = $this->getEntityManager()->createQuery(
            'SELECT t FROM NetresearchTimeTrackerBundle:Ticket t'
            . ' WHERE t.ticketSystemId = :ticketSystemId'
            . ' AND t.ticketNumber = :ticketNumber'
        )->setParameter('ticketSystemId', $ticketSystem->getId())
         ->setParameter('ticketNumber', $ticketNumber);

        $result = $query->getResult();
        if (! is_array($result) || !count($result))
            return null;

        return $result[0];
    }
}

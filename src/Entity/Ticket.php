<?php

declare(strict_types=1);

/**
 * Class for ticket entities in the timetracker.
 *
 * PHP version 5
 *
 * @category  Model_Class
 *
 * @author    Norman Kante <norman.kante@netresearch.de>
 * @copyright 2013 Netresearch App Factory AG
 * @license   No license
 *
 * @see      http://www.netresearch.de
 */

namespace App\Entity;

use App\Model\Base;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class Ticket.
 *
 * @category Model_Class
 *
 * @author   Norman Kante <norman.kante@netresearch.de>
 * @license  No license
 *
 * @see     http://www.netresearch.de
 */
#[ORM\Entity]
#[ORM\Table(name: 'tickets')]
class Ticket extends Base
{
    /**
     * Initialize a new Ticket with required properties.
     *
     * @param int    $ticketSystemId     The ticket system ID
     * @param string $ticketNumber       The ticket number/identifier
     * @param string $name               The ticket name/title
     * @param int    $estimatedDuration  Estimated duration in minutes (default: 0)
     * @param string $parentTicketNumber Parent ticket number (default: '')
     */
    public function __construct(
        #[ORM\Column(name: 'ticket_system_id', type: 'integer')]
        private int $ticketSystemId,
        #[ORM\Column(name: 'ticket_number', type: 'string', length: 31)]
        private string $ticketNumber,
        #[ORM\Column(type: 'string', length: 127)]
        private string $name,
        #[ORM\Column(name: 'estimation', type: 'integer')]
        private int $estimatedDuration = 0,
        #[ORM\Column(name: 'parent', type: 'string', length: 31)]
        private string $parentTicketNumber = '',
    ) {
    }

    /**
     * @var int
     */
    public $ticketId;

    /**
     * @var int
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /**
     * Sets the estimated duration.
     *
     * @param int $estimatedDuration estimated duration
     *
     * @return $this
     */
    public function setEstimatedDuration(int $estimatedDuration): static
    {
        $this->estimatedDuration = $estimatedDuration;

        return $this;
    }

    /**
     * Get the estimated duration.
     */
    public function getEstimatedDuration(): int
    {
        return $this->estimatedDuration;
    }

    /**
     * @return $this
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return $this
     */
    public function setParentTicketNumber(string $parentTicketNumber): static
    {
        $this->parentTicketNumber = $parentTicketNumber;

        return $this;
    }

    public function getParentTicketNumber(): string
    {
        return $this->parentTicketNumber;
    }

    /**
     * @param int $ticketId
     *
     * @return $this
     */
    public function setTicketId($ticketId): static
    {
        $this->ticketId = $ticketId;

        return $this;
    }

    /**
     * @return int
     */
    public function getTicketId()
    {
        return $this->ticketId;
    }

    /**
     * @return $this
     */
    public function setTicketNumber(string $ticketNumber): static
    {
        $this->ticketNumber = $ticketNumber;

        return $this;
    }

    public function getTicketNumber(): string
    {
        return $this->ticketNumber;
    }

    /**
     * @return $this
     */
    public function setTicketSystemId(int $ticketSystemId): static
    {
        $this->ticketSystemId = $ticketSystemId;

        return $this;
    }

    public function getTicketSystemId(): int
    {
        return $this->ticketSystemId;
    }

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }
}

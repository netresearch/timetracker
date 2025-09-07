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
        int $ticketSystemId,
        string $ticketNumber,
        string $name,
        int $estimatedDuration = 0,
        string $parentTicketNumber = '',
    ) {
        $this->ticketSystemId = $ticketSystemId;
        $this->ticketNumber = $ticketNumber;
        $this->name = $name;
        $this->estimatedDuration = $estimatedDuration;
        $this->parentTicketNumber = $parentTicketNumber;
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
     * @var int
     */
    #[ORM\Column(name: 'ticket_system_id', type: 'integer')]
    private $ticketSystemId;

    /**
     * @var string
     */
    #[ORM\Column(name: 'ticket_number', type: 'string', length: 31)]
    private $ticketNumber;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 127)]
    private $name;

    /**
     * @var int
     */
    #[ORM\Column(type: 'integer', name: 'estimation')]
    private $estimatedDuration;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', name: 'parent', length: 31)]
    private $parentTicketNumber;

    /**
     * Sets the estimated duration.
     *
     * @param int $estimatedDuration estimated duration
     *
     * @return $this
     */
    public function setEstimatedDuration($estimatedDuration): static
    {
        $this->estimatedDuration = $estimatedDuration;

        return $this;
    }

    /**
     * Get the estimated duration.
     *
     * @return int
     */
    public function getEstimatedDuration()
    {
        return $this->estimatedDuration;
    }

    /**
     * @param string $name
     *
     * @return $this
     */
    public function setName($name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $parentTicketNumber
     *
     * @return $this
     */
    public function setParentTicketNumber($parentTicketNumber): static
    {
        $this->parentTicketNumber = $parentTicketNumber;

        return $this;
    }

    /**
     * @return string
     */
    public function getParentTicketNumber()
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
     * @param string $ticketNumber
     *
     * @return $this
     */
    public function setTicketNumber($ticketNumber): static
    {
        $this->ticketNumber = $ticketNumber;

        return $this;
    }

    /**
     * @return string
     */
    public function getTicketNumber()
    {
        return $this->ticketNumber;
    }

    /**
     * @param int $ticketSystemId
     *
     * @return $this
     */
    public function setTicketSystemId($ticketSystemId): static
    {
        $this->ticketSystemId = $ticketSystemId;

        return $this;
    }

    /**
     * @return int
     */
    public function getTicketSystemId()
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

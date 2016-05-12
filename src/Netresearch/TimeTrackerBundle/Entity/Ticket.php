<?php
/**
 * Class for ticket entities in the timetracker
 *
 * PHP version 5
 *
 * @category  Model_Class
 * @package   Netresearch\TimeTrackerBundle\Extension
 * @author    Norman Kante <norman.kante@netresearch.de>
 * @copyright 2013 Netresearch App Factory AG
 * @license   No license
 * @link      http://www.netresearch.de
 */

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Netresearch\TimeTrackerBundle\Model\Base as Base;

/**
 * Class Ticket
 *
 * @category Model_Class
 * @package  Netresearch\TimeTrackerBundle\Model
 * @author   Norman Kante <norman.kante@netresearch.de>
 * @license  No license
 * @link     http://www.netresearch.de
 *
 * @ORM\Entity
 * @ORM\Table(name="tickets")
 * @ORM\Entity(repositoryClass="Netresearch\TimeTrackerBundle\Entity\TicketRepository")
 */
class Ticket extends Base
{

    /**
     * @var int
     *
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;


    /**
     * @var int
     *
     * @ORM\Column(name="ticket_system_id", type="integer")
     */
    private $ticketSystemId;


    /**
     * @var string
     *
     * @ORM\Column(name="ticket_number", type="string", length=31)
     */
    private $ticketNumber;


    /**
     * @var string
     *
     * @ORM\Column(type="string", length=127)
     */
    private $name;


    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="estimation")
     */
    private $estimatedDuration;


    /**
     * @var string
     *
     * @ORM\Column(type="string", name="parent", length=31)
     */
    private $parentTicketNumber;


    /**
     * Sets the estimated duration
     *
     * @param int $estimatedDuration estimated duration
     *
     * @return $this
     */
    public function setEstimatedDuration($estimatedDuration)
    {
        $this->estimatedDuration = $estimatedDuration;

        return $this;
    }

    /**
     * Get the estimated duration
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
    public function setName($name)
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
    public function setParentTicketNumber($parentTicketNumber)
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
    public function setTicketId($ticketId)
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
    public function setTicketNumber($ticketNumber)
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
    public function setTicketSystemId($ticketSystemId)
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
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }
}
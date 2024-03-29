<?php declare(strict_types=1);
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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Model\Base as Base;

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
#[ORM\Entity(repositoryClass: 'App\Repository\TicketRepository')]
#[ORM\Table(name: 'tickets')]
class Ticket extends Base
{
    /**
     * @var int
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(name: 'ticket_system_id', type: Types::INTEGER)]
    private int $ticketSystemId;

    #[ORM\Column(name: 'ticket_number', type: Types::STRING, length: 31)]
    private string $ticketNumber;

    #[ORM\Column(type: Types::STRING, length: 127)]
    private string $name;

    #[ORM\Column(type: Types::INTEGER, name: 'estimation')]
    private int $estimatedDuration;

    #[ORM\Column(type: Types::STRING, name: 'parent', length: 31)]
    private string $parentTicketNumber;

    /**
     * Sets the estimated duration.
     *
     * @param int $estimatedDuration estimated duration
     *
     * @return $this
     */
    public function setEstimatedDuration(int $estimatedDuration)
    {
        $this->estimatedDuration = $estimatedDuration;

        return $this;
    }

    /**
     * Get the estimated duration.
     *
     * @return int
     */
    public function getEstimatedDuration(): int
    {
        return $this->estimatedDuration;
    }

    /**
     * @param string $name
     *
     * @return $this
     */
    public function setName(string $name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $parentTicketNumber
     *
     * @return $this
     */
    public function setParentTicketNumber(string $parentTicketNumber)
    {
        $this->parentTicketNumber = $parentTicketNumber;

        return $this;
    }

    /**
     * @return string
     */
    public function getParentTicketNumber(): string
    {
        return $this->parentTicketNumber;
    }

    /**
     * @param int $ticketId
     *
     * @return $this
     */
    public function setTicketId(int $ticketId)
    {
        $this->ticketId = $ticketId;

        return $this;
    }

    /**
     * @return int
     */
    public function getTicketId(): int
    {
        return $this->ticketId;
    }

    /**
     * @param string $ticketNumber
     *
     * @return $this
     */
    public function setTicketNumber(string $ticketNumber)
    {
        $this->ticketNumber = $ticketNumber;

        return $this;
    }

    /**
     * @return string
     */
    public function getTicketNumber(): string
    {
        return $this->ticketNumber;
    }

    /**
     * @param int $ticketSystemId
     *
     * @return $this
     */
    public function setTicketSystemId(int $ticketSystemId)
    {
        $this->ticketSystemId = $ticketSystemId;

        return $this;
    }

    /**
     * @return int
     */
    public function getTicketSystemId(): int
    {
        return $this->ticketSystemId;
    }

    /**
     * Get id.
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }
}

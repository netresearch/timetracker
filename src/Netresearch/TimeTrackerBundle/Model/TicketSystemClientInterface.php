<?php
/**
 * Interface ticket system clients
 *
 * PHP version 5
 *
 * @category  Model_Interface
 * @package   Netresearch\TimeTrackerBundle\Extension
 * @author    Norman Kante <norman.kante@netresearch.de>
 * @copyright 2013 Netresearch App Factory AG
 * @license   No license
 * @link      http://www.netresearch.de
 */

namespace Netresearch\TimeTrackerBundle\Model;

use Netresearch\TimeTrackerBundle\Entity\Entry;
use Netresearch\TimeTrackerBundle\Entity\Ticket;
use Netresearch\TimeTrackerBundle\Entity\TicketSystem;

/**
 * Interface TicketSystemClientAbstract
 *
 * @category Model_Interface
 * @package  Netresearch\TimeTrackerBundle\Model
 * @author   Norman Kante <norman.kante@netresearch.de>
 * @license  No license
 * @link     http://www.netresearch.de
 */
interface TicketSystemClientInterface
{
    /**
     * Constructs a new client with passed ticket system data
     *
     * @param TicketSystem $ticketSystem
     */
    public function __construct(TicketSystem $ticketSystem);


    /**
     * login to a ticket system
     *
     * @return boolean
     */
    public function login();


    /**
     * checks the ticket if it is valid for the ticket system client
     *
     * @param string $ticketNumber ticket number
     *
     * @return boolean
     */
    public function isValidTicket($ticketNumber);


    /**
     * returns a ticket
     *
     * @param string $ticketNumber ticket number
     *
     * @return Ticket
     */
    public function getTicket($ticketNumber);


    /**
     * adds a worklog to the ticket system
     *
     * @param Entry $entry timtracker entry
     *
     * @return boolean
     */
    public function addWorklog(Entry $entry);


    /**
     * deletes an work log from the ticket system
     *
     * @param Entry $entry timetracker entry
     *
     * @return mixed
     */
    public function deleteWorklog(Entry $entry);


    /**
     * updates a work log in the ticket system
     *
     * @param Entry $entry    timetracker entry
     * @param Entry $oldEntry timetracker entry
     *
     * @return mixed
     */
    public function updateWorklog(Entry $entry, Entry $oldEntry);

}
